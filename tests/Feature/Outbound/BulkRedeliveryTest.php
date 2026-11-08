<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * After an outage: every failed delivery of an endpoint again, with one call or one command.
 */
beforeEach(function (): void {
    Queue::fake();
    publicDns();
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function failedDelivery(OutboundEndpoint $endpoint, array $attributes = []): OutboundDelivery
{
    return OutboundDelivery::factory()->status(OutboundStatus::Failed)->create(['endpoint_id' => $endpoint->getKey(), 'succeeded_at' => null, ...$attributes]);
}

it('redeliver_failed_queues_every_failed_delivery_of_the_endpoint', function (): void {
    $endpoint = outboundEndpoint();
    $failed = [failedDelivery($endpoint), failedDelivery($endpoint)];
    OutboundDelivery::factory()->create(['endpoint_id' => $endpoint->getKey()]);   // succeeded
    failedDelivery(outboundEndpoint('https://other.example.com/in'));               // another endpoint

    $result = Webhooks::outbound()->redeliverFailed($endpoint);

    expect($result->queued)->toBe(2)->and($result->skippedPruned)->toBe(0);
    foreach ($failed as $delivery) {
        expect($delivery->refresh()->status)->toBe(OutboundStatus::Pending)->and($delivery->series)->toBe(2);
    }
    Queue::assertPushed(DeliverWebhook::class, 2);
});

it('redeliver_failed_honours_since', function (): void {
    $endpoint = outboundEndpoint();
    $old = failedDelivery($endpoint, ['created_at' => CarbonImmutable::now()->subDays(2)]);
    $recent = failedDelivery($endpoint, ['created_at' => CarbonImmutable::now()->subHour()]);

    $result = Webhooks::outbound()->redeliverFailed($endpoint, since: CarbonImmutable::now()->subDay());

    expect($result->queued)->toBe(1)
        ->and($recent->refresh()->status)->toBe(OutboundStatus::Pending)
        ->and($old->refresh()->status)->toBe(OutboundStatus::Failed);
});

it('redeliver_failed_includes_cancelled_only_when_asked', function (): void {
    $endpoint = outboundEndpoint();
    $cancelled = OutboundDelivery::factory()->status(OutboundStatus::Cancelled)->create(['endpoint_id' => $endpoint->getKey()]);

    expect(Webhooks::outbound()->redeliverFailed($endpoint)->queued)->toBe(0)
        ->and($cancelled->refresh()->status)->toBe(OutboundStatus::Cancelled)
        ->and(Webhooks::outbound()->redeliverFailed($endpoint, includeCancelled: true)->queued)->toBe(1)
        ->and($cancelled->refresh()->status)->toBe(OutboundStatus::Pending);
});

it('redeliver_failed_skips_pruned_bodies', function (): void {
    $endpoint = outboundEndpoint();
    failedDelivery($endpoint, ['body' => null, 'payload_pruned_at' => CarbonImmutable::now()]);
    failedDelivery($endpoint);

    $result = Webhooks::outbound()->redeliverFailed($endpoint);

    expect($result->queued)->toBe(1)->and($result->skippedPruned)->toBe(1);
});

it('redeliver_failed_refuses_another_owners_endpoint', function (): void {
    $acme = team('Acme');
    $theirs = outboundEndpoint(attributes: ['owner_type' => $acme->getMorphClass(), 'owner_id' => team('Other')->getKey()]);
    $disabled = outboundEndpoint(attributes: ['enabled' => false]);
    failedDelivery($theirs);

    expect(fn () => Webhooks::for($acme)->outbound()->redeliverFailed($theirs))->toThrow(EndpointNotFoundException::class)
        ->and(fn () => Webhooks::outbound()->redeliverFailed($disabled))->toThrow(EndpointNotAcceptingException::class);
    Queue::assertNothingPushed();
});

it('redeliver_command_bulk_mode', function (): void {
    $endpoint = outboundEndpoint();
    failedDelivery($endpoint, ['created_at' => CarbonImmutable::now()->subDays(3)]);
    failedDelivery($endpoint, ['created_at' => CarbonImmutable::now()->subHour()]);
    OutboundDelivery::factory()->status(OutboundStatus::Cancelled)->create(['endpoint_id' => $endpoint->getKey()]);

    $this->artisan('webhooks:redeliver', ['--endpoint' => (string) $endpoint->getKey(), '--since' => '2026-11-07'])
        ->expectsOutputToContain('Queued 1 delivery(ies) again; skipped 0')
        ->assertSuccessful();
    $this->artisan('webhooks:redeliver', ['--endpoint' => (string) $endpoint->getKey(), '--cancelled' => true])
        ->expectsOutputToContain('Queued 2 delivery(ies) again')
        ->assertSuccessful();

    $this->artisan('webhooks:redeliver', ['--endpoint' => (string) $endpoint->getKey(), '--since' => 'not a date'])->expectsOutputToContain('not a date')->assertFailed();
    $this->artisan('webhooks:redeliver', ['--endpoint' => '999999'])->expectsOutputToContain('endpoint was not found')->assertFailed();
    $this->artisan('webhooks:redeliver')->expectsOutputToContain('--endpoint=')->assertFailed();
});

it('fake_records_bulk_redelivery', function (): void {
    $endpoint = outboundEndpoint();
    $other = outboundEndpoint('https://other.example.com/in');
    failedDelivery($endpoint);
    failedDelivery($endpoint, ['body' => null]);
    $fake = Webhooks::fake();

    $fails = fn () => $fake->assertRedelivered($endpoint);
    expect($fails)->toThrow(ExpectationFailedException::class, "endpoint's failed deliveries");

    $result = Webhooks::outbound()->redeliverFailed($endpoint);

    expect($result->queued)->toBe(1)->and($result->skippedPruned)->toBe(1)
        ->and(OutboundDelivery::query()->where('status', OutboundStatus::Pending->value)->count())->toBe(0);
    Queue::assertNothingPushed();
    $fake->assertRedelivered($endpoint);
    expect(fn () => $fake->assertRedelivered($other))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingRedelivered())->toThrow(ExpectationFailedException::class, 'but 1')
        ->and(fn () => Webhooks::outbound()->redeliverFailed(outboundEndpoint(attributes: ['enabled' => false])))->toThrow(EndpointNotAcceptingException::class);
});
