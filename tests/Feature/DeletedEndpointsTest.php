<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Events\WebhookProcessingFailed;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models\HandledWebhook;

/**
 * A deleted endpoint is dead: its secrets (and an outbound URL and headers) are wiped at once,
 * the row goes once retention has removed its deliveries, and a delivery still waiting for it
 * fails instead of sitting in `queued` forever.
 */
beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

it('deleting_an_endpoint_clears_its_secrets', function (): void {
    $inbound = inboundEndpoint();
    Webhooks::inbound()->rotateSecret($inbound);
    $outbound = Webhooks::outbound()->create('https://hooks.example.com/in?token=abc', headers: ['X-Tenant' => 'acme'])->endpoint;
    Webhooks::outbound()->rotateSecret($outbound);

    Webhooks::inbound()->delete($inbound);
    Webhooks::outbound()->delete($outbound);

    $inbound = InboundEndpoint::query()->withTrashed()->findOrFail($inbound->getKey());
    $outbound = OutboundEndpoint::query()->withTrashed()->findOrFail($outbound->getKey());

    expect($inbound->trashed())->toBeTrue()
        ->and($inbound->secret)->toBeNull()
        ->and($inbound->previous_secret)->toBeNull()
        ->and($inbound->previous_secret_expires_at)->toBeNull()
        ->and($outbound->trashed())->toBeTrue()
        ->and($outbound->secret)->toBe('')
        ->and($outbound->previous_secret)->toBeNull()
        ->and($outbound->url)->toBe('')
        ->and($outbound->headers)->toBeNull()
        // The host stays for the delivery log.
        ->and($outbound->url_host)->toBe('hooks.example.com');
});

it('prune_force_deletes_trashed_endpoints_without_deliveries', function (): void {
    $old = inboundEndpoint();
    $recent = inboundEndpoint();
    $outbound = outboundEndpoint();
    Webhooks::inbound()->delete($old);
    Webhooks::outbound()->delete($outbound);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(91));
    Webhooks::inbound()->delete($recent);
    $result = Webhooks::prune();
    CarbonImmutable::setTestNow();

    expect($result->endpoints)->toBe(2)
        ->and(InboundEndpoint::query()->withTrashed()->pluck('id')->all())->toBe([$recent->getKey()])
        ->and(OutboundEndpoint::query()->withTrashed()->count())->toBe(0);
});

it('prune_keeps_a_trashed_endpoint_still_referenced', function (): void {
    $inbound = inboundEndpoint();
    $outbound = outboundEndpoint();
    InboundDelivery::factory()->create(['endpoint_id' => $inbound->getKey()]);
    OutboundDelivery::factory()->create(['endpoint_id' => $outbound->getKey()]);
    Webhooks::inbound()->delete($inbound);
    Webhooks::outbound()->delete($outbound);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(91));
    $dry = Webhooks::prune(new PruneOptions(dryRun: true));
    CarbonImmutable::setTestNow();

    // The deliveries are 91 days old as well — but a dry run removes nothing, so they still hold.
    expect($dry->endpoints)->toBe(0)
        ->and(InboundEndpoint::query()->withTrashed()->count())->toBe(1);

    config()->set('webhooks.payloads.delivery_retention_days', null);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(400));
    $kept = Webhooks::prune();
    CarbonImmutable::setTestNow();

    // No delivery retention: deliveries stay, so their endpoints stay too.
    expect($kept->endpoints)->toBe(0)
        ->and(OutboundEndpoint::query()->withTrashed()->count())->toBe(1);
});

it('a_delivery_of_a_deleted_endpoint_fails_instead_of_staying_queued', function (): void {
    Event::fake([WebhookProcessingFailed::class, WebhookReceived::class]);
    $endpoint = inboundEndpoint(['handler' => 'billing']);
    postSigned($endpoint)->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();
    expect($delivery->status)->toBe(InboundStatus::Queued);

    Webhooks::inbound()->delete($endpoint);
    app()->call([new ProcessInboundDelivery($delivery->getKey()), 'handle']);

    $delivery->refresh();
    expect($delivery->status)->toBe(InboundStatus::Failed)
        ->and($delivery->last_error_code)->toBe('endpoint_deleted')
        ->and($delivery->failed_at)->not->toBeNull()
        ->and(HandledWebhook::query()->count())->toBe(0);
    Event::assertDispatched(WebhookProcessingFailed::class, fn (WebhookProcessingFailed $event): bool => $event->errorCode === 'endpoint_deleted' && $event->delivery->is($delivery));
    Event::assertNotDispatched(WebhookReceived::class);
});
