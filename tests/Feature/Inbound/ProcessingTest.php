<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Events\WebhookProcessingFailed;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers\FailingHandler;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models\HandledWebhook;

/**
 * Processing on the (sync) queue: the handler runs once per delivery and run, after
 * WebhookReceived; never inside the pipeline itself.
 */
it('an_endpoint_without_a_handler_fires_the_event_only', function (): void {
    Event::fake([WebhookReceived::class]);
    $endpoint = inboundEndpoint();

    postSigned($endpoint, ['type' => 'invoice.paid', 'data' => ['id' => 1]])->assertStatus(202);

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->webhook->type === 'invoice.paid'
        && $event->webhook->payload['data'] === ['id' => 1] && ! $event->webhook->isReplay && $event->webhook->run === 1);
    expect(HandledWebhook::query()->count())->toBe(0)
        ->and(InboundDelivery::query()->sole()->status)->toBe(InboundStatus::Processed);
});

it('runs the bound handler with the sanitized payload', function (): void {
    $endpoint = inboundEndpoint(['handler' => 'billing']);

    postSigned($endpoint, ['type' => 'invoice.paid', 'secret' => 'shh'])->assertStatus(202);

    $delivery = InboundDelivery::query()->sole();
    expect(HandledWebhook::query()->sole()->delivery_id)->toBe($delivery->public_id)
        ->and($delivery->status)->toBe(InboundStatus::Processed)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->duration_ms)->toBeInt();
});

it('a_duplicated_inbound_job_runs_the_handler_once', function (): void {
    $endpoint = inboundEndpoint(['handler' => 'billing']);
    postSigned($endpoint)->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();

    // A double dispatch of the same run, a sender retry — the idempotent middleware skips both.
    dispatch(new ProcessInboundDelivery($delivery->getKey(), 1));
    dispatch(new ProcessInboundDelivery($delivery->getKey(), 1));

    expect(HandledWebhook::query()->count())->toBe(1);
});

it('a_replay_reruns_the_handler_flagged_as_a_replay', function (): void {
    $endpoint = inboundEndpoint(['handler' => 'billing']);
    postSigned($endpoint)->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();

    Webhooks::inbound()->replay($delivery);

    $runs = HandledWebhook::query()->orderBy('id')->get();
    expect($runs->pluck('replay')->all())->toBe([false, true])
        ->and($runs->pluck('run')->all())->toBe([1, 2])
        ->and($delivery->refresh()->runs)->toBe(2);
});

it('a_failing_handler_is_retried_then_marked_failed', function (): void {
    Event::fake([WebhookProcessingFailed::class]);
    config()->set('webhooks.handlers', ['failing' => FailingHandler::class]);
    config()->set('webhooks.inbound.handler.tries', 2);
    Queue::fake();
    $endpoint = inboundEndpoint(['handler' => 'failing']);
    postSigned($endpoint)->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();

    $job = new ProcessInboundDelivery($delivery->getKey());
    expect($job->tries)->toBe(2)->and($job->backoff())->toBe([10, 60, 300])->and($job->timeout)->toBe(60);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'ledger down');

    $job->failed(new RuntimeException('ledger down'));

    $delivery->refresh();
    expect($delivery->status)->toBe(InboundStatus::Failed)
        ->and($delivery->last_error_code)->toBe('handler_failed')
        ->and($delivery->last_error_message)->toBe('RuntimeException: ledger down');
    Event::assertDispatched(WebhookProcessingFailed::class, fn (WebhookProcessingFailed $event): bool => $event->errorCode === 'handler_failed');
});

it('fails a run whose handler alias is gone', function (): void {
    Queue::fake();
    $endpoint = inboundEndpoint(['handler' => 'vanished']);
    postSigned($endpoint)->assertStatus(202);

    expect(fn () => app()->call([new ProcessInboundDelivery(InboundDelivery::query()->sole()->getKey()), 'handle']))->toThrow(UnknownHandlerException::class);

    config()->set('webhooks.handlers', ['vanished' => FailingHandler::class]);
    app()->bind(FailingHandler::class, fn () => new stdClass);
    expect(fn () => app()->call([new ProcessInboundDelivery(InboundDelivery::query()->sole()->getKey()), 'handle']))->toThrow(UnknownHandlerException::class);
});

it('ignores a job whose delivery is gone', function (): void {
    app()->call([new ProcessInboundDelivery(999), 'handle']);
    (new ProcessInboundDelivery(999))->failed(null);

    expect(InboundDelivery::query()->count())->toBe(0);
});

it('replaying_a_pruned_delivery_throws', function (): void {
    $delivery = InboundDelivery::factory()->create(['received_at' => CarbonImmutable::now()->subDays(40)]);
    Webhooks::prune();

    expect(fn () => Webhooks::inbound()->replay($delivery->refresh()))->toThrow(PayloadPrunedException::class);
});

it('prune_clears_expired_previous_secrets', function (): void {
    $endpoint = inboundEndpoint();
    Webhooks::inbound()->rotateSecret($endpoint, CarbonImmutable::now()->addHour());
    $outbound = outboundEndpoint();
    Webhooks::outbound()->rotateSecret($outbound, CarbonImmutable::now()->addHour());

    expect(Webhooks::prune()->expiredSecrets)->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));
    $result = Webhooks::prune();
    CarbonImmutable::setTestNow();

    expect($result->expiredSecrets)->toBe(2)
        ->and($endpoint->refresh()->previous_secret)->toBeNull()
        ->and($outbound->refresh()->previous_secret)->toBeNull();
});

it('keeps delivery rows when delivery retention is off', function (): void {
    config()->set('webhooks.payloads.delivery_retention_days', null);
    InboundDelivery::factory()->create(['received_at' => CarbonImmutable::now()->subDays(400)]);
    $attempt = OutboundAttempt::factory()->create(['started_at' => CarbonImmutable::now()->subDays(400), 'response_body' => 'x']);
    $attempt->delivery?->forceFill(['created_at' => CarbonImmutable::now()->subDays(400)])->save();

    $result = Webhooks::prune();

    expect($result->inboundDeliveries)->toBe(0)->and($result->outboundDeliveries)->toBe(0)
        ->and($result->attemptBodies)->toBe(1)->and($result->outboundBodies)->toBe(1)
        ->and(InboundDelivery::query()->count())->toBe(1);

    config()->set('webhooks.payloads.delivery_retention_days', 90);
    $deleted = Webhooks::prune();
    expect($deleted->inboundDeliveries)->toBe(1)->and($deleted->outboundDeliveries)->toBe(1)->and($deleted->attempts)->toBe(1);
});
