<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Actions\Inbound\ProcessInboundDeliveryAction;
use RoundlyConsulting\Webhooks\Enums\Direction;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Events\DeliveryFailed;
use RoundlyConsulting\Webhooks\Events\DeliverySucceeded;
use RoundlyConsulting\Webhooks\Events\EndpointDisabled;
use RoundlyConsulting\Webhooks\Events\EndpointSecretRotated;
use RoundlyConsulting\Webhooks\Events\WebhookProcessingFailed;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers\BillingWebhookHandler;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers\FailingHandler;

/**
 * Events a queued listener can take: models travel as identifiers and are re-fetched, so the
 * listener sees the row as it is when it runs, not a snapshot.
 */
it('queued_listener_receives_fresh_models', function (): void {
    $attempt = OutboundAttempt::factory()->create();
    $delivery = $attempt->delivery ?? throw new LogicException('no delivery');
    $endpoint = outboundEndpoint();
    $inbound = InboundDelivery::factory()->create();

    $events = [
        new DeliveryFailed($delivery, $attempt, false, CarbonImmutable::now()),
        new DeliverySucceeded($delivery, $attempt),
        new EndpointDisabled($endpoint, DisableReason::Manual),
        new EndpointSecretRotated($endpoint, Direction::Outbound, CarbonImmutable::now()),
        new WebhookProcessingFailed($inbound, 'handler_failed'),
    ];
    $queued = array_map(serialize(...), $events);

    // The rows change after dispatch, before the queued listener runs.
    $delivery->forceFill(['status' => OutboundStatus::Succeeded])->save();
    $endpoint->forceFill(['name' => 'renamed'])->save();
    $inbound->forceFill(['last_error_code' => 'changed'])->save();

    [$failed, $succeeded, $disabled, $rotated, $processing] = array_map(unserialize(...), $queued);

    expect($failed->delivery->status)->toBe(OutboundStatus::Succeeded)
        ->and($failed->attempt->is($attempt))->toBeTrue()
        ->and($failed->final)->toBeFalse()
        ->and($succeeded->delivery->status)->toBe(OutboundStatus::Succeeded)
        ->and($disabled->endpoint->name)->toBe('renamed')
        ->and($disabled->reason)->toBe(DisableReason::Manual)
        ->and($rotated->endpoint->name)->toBe('renamed')
        ->and($processing->delivery->last_error_code)->toBe('changed')
        ->and(serialize($events[0]))->not->toContain('"status"');
});

it('webhook_received_fires_once_per_attempt_and_says_which', function (): void {
    Queue::fake();
    Event::fake([WebhookReceived::class]);
    config()->set('webhooks.handlers', ['billing' => FailingHandler::class]);
    $endpoint = inboundEndpoint(['handler' => 'billing']);
    postSigned($endpoint)->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();
    $action = app(ProcessInboundDeliveryAction::class);

    expect(fn () => $action->execute($delivery->getKey(), 1, false, 1))->toThrow(RuntimeException::class);
    config()->set('webhooks.handlers', ['billing' => BillingWebhookHandler::class]);
    $action->execute($delivery->getKey(), 1, false, 2);

    $seen = [];
    Event::assertDispatchedTimes(WebhookReceived::class, 2);
    Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $event) use (&$seen): bool {
        $seen[] = [$event->webhook->attempt, $event->webhook->isFirstAttempt()];

        return true;
    });
    expect($seen)->toBe([[1, true], [2, false]])
        ->and(OutboundDelivery::query()->count())->toBe(0);
});
