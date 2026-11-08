<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware\HandlesRateLimitRelease;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\Sentinel\Jobs\Middleware\Idempotent;
use RoundlyConsulting\Webhooks\Actions\Outbound\AttemptDeliveryAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\DisableOutboundEndpointAction;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Events\DeliveryFailed;
use RoundlyConsulting\Webhooks\Events\DeliverySucceeded;
use RoundlyConsulting\Webhooks\Events\EndpointDisabled;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Support\Backoff;
use RoundlyConsulting\Webhooks\Tests\Support\FixedRandomizer;

beforeEach(function (): void {
    Queue::fake();
    publicDns();
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('an_invalid_event_type_is_refused', function (string $type): void {
    expect(fn () => Webhooks::send(outboundEndpoint(), $type, []))->toThrow(InvalidEventTypeException::class);
})->with(['Invoice.Paid', 'invoice paid', 'invoice..paid', '.invoice', str_repeat('a', 101)]);

it('an_unknown_event_type_is_refused_when_types_are_listed', function (): void {
    config()->set('webhooks.outbound.event_types', ['invoice.paid']);

    Webhooks::send(outboundEndpoint(), 'invoice.paid', []);
    expect(fn () => Webhooks::send(outboundEndpoint(), 'invoice.void', []))->toThrow(InvalidEventTypeException::class, 'not one of the configured');
});

it('sending_to_a_disabled_endpoint_throws', function (): void {
    $endpoint = outboundEndpoint(attributes: ['enabled' => false]);

    expect(fn () => Webhooks::send($endpoint, 'invoice.paid', []))->toThrow(EndpointNotAcceptingException::class, 'disabled');

    try {
        Webhooks::send(outboundEndpoint(events: ['order.*']), 'invoice.paid', []);
    } catch (EndpointNotAcceptingException $exception) {
        expect($exception->reason())->toBe('not_subscribed');
    }

    try {
        Webhooks::send($endpoint, 'invoice.paid', []);
    } catch (EndpointNotAcceptingException $exception) {
        expect($exception->reason())->toBe('disabled');
    }
});

it('wildcard_subscriptions_match', function (): void {
    expect(outboundEndpoint(events: ['*'])->subscribesTo('a.b'))->toBeTrue()
        ->and(outboundEndpoint(events: ['invoice.*'])->subscribesTo('invoice.paid'))->toBeTrue()
        ->and(outboundEndpoint(events: ['invoice.*'])->subscribesTo('invoice'))->toBeFalse()
        ->and(outboundEndpoint(events: ['invoice.*'])->subscribesTo('invoices.paid'))->toBeFalse()
        ->and(outboundEndpoint(events: ['invoice.paid'])->subscribesTo('invoice.paid'))->toBeTrue()
        ->and(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', events: []))->toThrow(InvalidEndpointException::class)
        ->and(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', events: ['Bad Type']))->toThrow(InvalidEndpointException::class)
        ->and(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', events: [7]))->toThrow(InvalidEndpointException::class);
});

it('every_attempt_sends_the_same_body', function (): void {
    Http::fake(['*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42, 'price' => 1.0, 'name' => 'Čaj/ä']);

    attempt($delivery);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
    attempt($delivery);

    $bodies = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->unique()->all();
    expect($bodies)->toHaveCount(1)
        ->and(reset($bodies))->toBe('{"type":"invoice.paid","timestamp":"2026-11-08T12:00:00Z","data":{"invoice":42,"price":1.0,"name":"Čaj/ä"}}')
        ->and($delivery->status)->toBe(OutboundStatus::Succeeded);
});

it('sends the payload as is without the envelope', function (): void {
    config()->set('webhooks.outbound.envelope', false);
    Http::fake(['*' => Http::response('ok')]);

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', collect(['invoice' => 1])));

    Http::assertSent(fn (ClientRequest $request): bool => $request->body() === '{"invoice":1}');
});

it('an_oversized_outbound_payload_is_refused', function (): void {
    config()->set('webhooks.outbound.max_payload_bytes', 100);

    expect(fn () => Webhooks::send(outboundEndpoint(), 'invoice.paid', ['pad' => str_repeat('x', 100)]))->toThrow(PayloadTooLargeException::class);
});

it('broadcast_reaches_only_the_owners_subscribed_endpoints', function (): void {
    $acme = team('Acme');
    $other = team('Other');
    $subscribed = outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $acme->getMorphClass(), 'owner_id' => $acme->getKey()]);
    outboundEndpoint(events: ['order.*'], attributes: ['owner_type' => $acme->getMorphClass(), 'owner_id' => $acme->getKey()]);
    outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $acme->getMorphClass(), 'owner_id' => $acme->getKey(), 'enabled' => false]);
    outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $other->getMorphClass(), 'owner_id' => $other->getKey()]);
    outboundEndpoint(events: ['invoice.*']);   // owner-less

    $deliveries = Webhooks::for($acme)->broadcast('invoice.paid', ['invoice' => 1]);

    expect($deliveries->pluck('endpoint_id')->all())->toBe([$subscribed->getKey()])
        ->and(Webhooks::broadcast('invoice.paid', ['invoice' => 1]))->toHaveCount(1)
        ->and(fn () => Webhooks::broadcast('Bad', []))->toThrow(InvalidEventTypeException::class);
});

it('a_double_dispatched_attempt_sends_once', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 1]);
    $token = (string) $delivery->refresh()->dispatch_token;

    // The same job twice — the second finds the row claimed / finished and sends nothing.
    $job = new DeliverWebhook($delivery->getKey(), $token);
    app(AttemptDeliveryAction::class)->execute($delivery->getKey(), $token, $job);
    app(AttemptDeliveryAction::class)->execute($delivery->getKey(), $token, $job);
    // A superseded job (an older token) sends nothing either.
    app(AttemptDeliveryAction::class)->execute($delivery->getKey(), 'stale-token', $job);

    Http::assertSentCount(1);
    expect($delivery->refresh()->attempts)->toBe(1);
});

it('a_delivery_to_an_endpoint_disabled_meanwhile_is_cancelled', function (): void {
    Http::fake();
    $endpoint = outboundEndpoint();
    $delivery = Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 1]);
    $endpoint->forceFill(['enabled' => false])->save();

    attempt($delivery);

    Http::assertNothingSent();
    expect($delivery->status)->toBe(OutboundStatus::Cancelled);
});

it('a_throttled_delivery_is_released_without_consuming_an_attempt', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $endpoint = outboundEndpoint(attributes: ['rate_limit_per_minute' => 1]);
    attempt(Webhooks::send($endpoint, 'invoice.paid', ['n' => 1]));
    $delivery = Webhooks::send($endpoint, 'invoice.paid', ['n' => 2]);
    $token = (string) $delivery->refresh()->dispatch_token;

    // A job that really sits on a queue: the throttle releases it (and the row keeps its token).
    $job = new DeliverWebhook($delivery->getKey(), $token);
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once();
    $queued->shouldReceive('isReleased')->andReturn(true);
    $queued->shouldReceive('isDeletedOrReleased')->andReturn(true);
    $job->setJob($queued);

    expect(fn () => app(AttemptDeliveryAction::class)->execute($delivery->getKey(), $token, $job))->toThrow(JobReleasedException::class);

    $delivery->refresh();
    expect($delivery->status)->toBe(OutboundStatus::Pending)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->dispatch_token)->toBe($token)
        ->and($delivery->claimed_until)->toBeNull()
        ->and(OutboundAttempt::query()->where('delivery_id', $delivery->getKey())->count())->toBe(0);

    // The released job claims again a minute later.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute()->addSecond());
    app(RateLimitManager::class)->store();
    attempt($delivery);
    expect($delivery->status)->toBe(OutboundStatus::Succeeded);
});

it('an_adaptive_penalty_beyond_max_wait_parks_the_delivery', function (): void {
    config()->set('webhooks.queue.max_delay', 60);
    Http::fake(['*' => Http::sequence()->push('slow', 429, ['Retry-After' => '3600'])->push('ok', 200)]);
    $endpoint = outboundEndpoint();
    $first = attempt(Webhooks::send($endpoint, 'invoice.paid', ['n' => 1]));
    expect($first->status)->toBe(OutboundStatus::Retrying);

    // The receiver's Retry-After penalises the endpoint's key: the next send waits beyond maxWait.
    $second = attempt(Webhooks::send($endpoint, 'invoice.paid', ['n' => 2]));

    expect($second->status)->toBe(OutboundStatus::Pending)
        ->and($second->dispatch_token)->toBeNull()
        ->and($second->attempts)->toBe(0)
        ->and($second->next_attempt_at?->getTimestamp())->toBeGreaterThan(CarbonImmutable::now()->addMinutes(30)->getTimestamp());
});

it('a_throttled_sync_delivery_is_not_lost', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $endpoint = outboundEndpoint(attributes: ['rate_limit_per_minute' => 1]);
    attempt(Webhooks::send($endpoint, 'invoice.paid', ['n' => 1]));

    // No queue job behind it (sync / dispatchSync): the release is a no-op, so the row is parked
    // for the sweep instead of vanishing.
    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['n' => 2]));

    expect($delivery->status)->toBe(OutboundStatus::Pending)->and($delivery->dispatch_token)->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
    expect(Webhooks::outbound()->dispatchDue())->toBe(1);
    attempt($delivery);
    expect($delivery->status)->toBe(OutboundStatus::Succeeded);
});

it('a_released_delivery_is_not_failed_by_the_worker_tries', function (): void {
    $job = new DeliverWebhook(1, 'token');

    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->timeout)->toBe(30)
        ->and(array_map(get_class(...), $job->middleware()))->toBe([
            Idempotent::class,
            HandlesRateLimitRelease::class,
        ]);
});

it('unexpected_exceptions_hand_the_row_to_the_sweep', function (): void {
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]);
    $token = (string) $delivery->refresh()->dispatch_token;

    (new DeliverWebhook($delivery->getKey(), $token))->failed(new RuntimeException('database down'));

    $delivery->refresh();
    expect($delivery->status)->toBe(OutboundStatus::Retrying)
        ->and($delivery->dispatch_token)->toBeNull()
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-11-08 12:01:00');
});

it('the_response_body_is_capped', function (): void {
    config()->set('webhooks.outbound.max_response_bytes', 16);
    Http::fake(['*' => Http::response(str_repeat('a', 100), 500)]);

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]));

    expect(OutboundAttempt::query()->sole()->response_body)->toBe(str_repeat('a', 16));
});

it('a_binary_response_is_stored_safely', function (): void {
    Http::fake(['*' => Http::response("\xff\xfe\x00bin", 500)]);

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]));

    $body = (string) OutboundAttempt::query()->sole()->response_body;
    expect(mb_check_encoding($body, 'UTF-8'))->toBeTrue()->and($body)->not->toContain("\0");
});

it('a_410_disables_the_endpoint', function (): void {
    Event::fake([EndpointDisabled::class, DeliveryFailed::class]);
    Http::fake(['*' => Http::response('gone', 410)]);
    $endpoint = outboundEndpoint();
    $waiting = Webhooks::send($endpoint, 'invoice.paid', ['n' => 2]);

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['n' => 1]));

    expect($delivery->status)->toBe(OutboundStatus::Failed)
        ->and($endpoint->refresh()->enabled)->toBeFalse()
        ->and($endpoint->disabled_reason)->toBe(DisableReason::Gone)
        ->and($waiting->refresh()->status)->toBe(OutboundStatus::Cancelled);
    Event::assertDispatched(EndpointDisabled::class, fn (EndpointDisabled $event): bool => $event->reason === DisableReason::Gone);
    Event::assertDispatched(DeliveryFailed::class, fn (DeliveryFailed $event): bool => $event->final);
});

it('connect_and_total_timeouts_are_applied', function (): void {
    Http::fake(function (ClientRequest $request, array $options) {
        return Http::response(json_encode(['connect' => $options['connect_timeout'] ?? null, 'total' => $options['timeout'] ?? null]));
    });

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]));

    $answer = json_decode((string) OutboundAttempt::query()->sole()->response_body, true);
    expect($answer['connect'])->toBe(5)->and($answer['total'])->toBeLessThanOrEqual(10)->toBeGreaterThan(9);
});

it('a_timeout_is_a_retryable_failure', function (int $errno, AttemptError $error): void {
    Http::fake(fn () => throw new ConnectException("cURL error {$errno}: secret details of vendor", new PsrRequest('POST', 'https://hooks.example.com/in')));

    $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]));

    expect($delivery->status)->toBe(OutboundStatus::Retrying)
        ->and($delivery->last_error_code)->toBe($error)
        ->and($delivery->last_error_message)->toBe("{$error->value} (curl {$errno})");
})->with([
    'timeout' => [28, AttemptError::Timeout],
    'tls' => [60, AttemptError::Tls],
    'dns' => [6, AttemptError::Unresolved],
    'refused' => [7, AttemptError::Connection],
]);

it('stored_errors_never_carry_a_class_name', function (): void {
    Http::fake(fn () => throw new ConnectException('Something without a number at App\\Http\\Thing', new PsrRequest('POST', 'https://hooks.example.com/in')));

    $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]));

    expect($delivery->last_error_message)->toBe('connection')
        ->and(OutboundAttempt::query()->sole()->error_message)->not->toContain('\\');
});

it('the_default_schedule_sums_to_about_24_hours', function (): void {
    $backoff = new Backoff(new FixedRandomizer(0));
    $delays = array_map(fn (int $n): int => $backoff->delay($n), range(1, 7));

    expect($delays)->toBe([60, 240, 960, 3840, 15360, 32400, 32400])
        ->and(array_sum($delays))->toBe(85260);   // 23 h 41 m before the 8th attempt
});

it('jitter_stays_within_ten_percent', function (): void {
    expect((new Backoff(new FixedRandomizer(100)))->delay(1))->toBe(66)
        ->and((new Backoff(new FixedRandomizer(-100)))->delay(1))->toBe(54)
        ->and((new Backoff(new FixedRandomizer(900)))->delay(1))->toBe(66);   // clamped

    config()->set('webhooks.outbound.retry.jitter_permille', 0);
    expect((new Backoff(new FixedRandomizer(100)))->delay(1))->toBe(60);
});

it('an_explicit_schedule_overrides_the_formula', function (): void {
    config()->set('webhooks.outbound.retry.schedule', [5, 30, 600]);
    $backoff = new Backoff(new FixedRandomizer(0));

    expect([$backoff->delay(1), $backoff->delay(2), $backoff->delay(3), $backoff->delay(9)])->toBe([5, 30, 600, 600]);

    config()->set('webhooks.outbound.retry.schedule', '10, 20');
    expect($backoff->delay(2))->toBe(20);
});

it('retry_after_is_capped', function (): void {
    $backoff = new Backoff(new FixedRandomizer(0));

    expect($backoff->delay(1, 999999))->toBe(86400)
        ->and($backoff->delay(1, 30))->toBe(60)
        ->and($backoff->delay(1, 120))->toBe(120);

    config()->set('webhooks.outbound.retry.base', 1);
    config()->set('webhooks.outbound.retry.jitter_permille', 500);
    expect((new Backoff(new FixedRandomizer(-500)))->delay(1))->toBe(1);   // never under a second
});

it('delays_beyond_the_queue_limit_wait_for_the_sweep', function (): void {
    Http::fake(['*' => Http::response('down', 500)]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]);
    $delivery->forceFill(['attempts' => 3])->save();

    attempt($delivery);   // 4th failure: 3840 s > 900 s

    expect($delivery->dispatch_token)->toBeNull();
    Queue::assertPushed(DeliverWebhook::class, 1);   // only the first dispatch

    $short = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 2]);
    attempt($short);   // 1st failure: 60 s — a delayed job
    expect($short->dispatch_token)->not->toBeNull();
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->delay === 60);

    // The sweep leaves a short-delay job alone while it is merely late…
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(90));
    expect(Webhooks::outbound()->dispatchDue())->toBe(0);
    // …and re-dispatches it once it is lost (due for longer than requeue_after).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));
    expect(Webhooks::outbound()->dispatchDue())->toBe(1);
    // Re-dispatched, it is not lost again until requeue_after has passed once more.
    expect(Webhooks::outbound()->dispatchDue())->toBe(0);
    // The long one goes out when due (the short one, still not run, counts as lost again).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));
    expect(Webhooks::outbound()->dispatchDue())->toBe(2);
});

it('an_expired_claim_is_requeued', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]);
    // A worker claimed it and died.
    $delivery->forceFill(['status' => OutboundStatus::Sending, 'claimed_until' => CarbonImmutable::now()->addSeconds(45)])->save();

    expect(Webhooks::outbound()->dispatchDue())->toBe(0);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    expect(Webhooks::outbound()->dispatchDue())->toBe(1);

    $lost = OutboundAttempt::query()->sole();
    expect($lost->error_code)->toBe(AttemptError::WorkerLost)
        ->and($delivery->refresh()->status)->toBe(OutboundStatus::Retrying)
        ->and($delivery->attempts)->toBe(1);

    attempt($delivery);
    expect($delivery->status)->toBe(OutboundStatus::Succeeded)->and($delivery->attempts)->toBe(2);

    // A lost last attempt fails the delivery.
    $last = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 2]);
    $last->forceFill(['status' => OutboundStatus::Sending, 'attempts' => 7, 'claimed_until' => CarbonImmutable::now()->subSecond()])->save();
    Event::fake([DeliveryFailed::class]);
    Webhooks::outbound()->dispatchDue();
    expect($last->refresh()->status)->toBe(OutboundStatus::Failed)
        ->and($last->endpoint?->consecutive_failures)->toBe(1);
    Event::assertDispatched(DeliveryFailed::class, fn (DeliveryFailed $event): bool => $event->final && $event->attempt->error_code === AttemptError::WorkerLost);
});

it('racing_failures_disable_the_endpoint_once', function (): void {
    Event::fake([EndpointDisabled::class]);
    $endpoint = outboundEndpoint(attributes: ['consecutive_failures' => 5]);
    $disable = app(DisableOutboundEndpointAction::class);

    $first = $disable->execute($endpoint, DisableReason::ConsecutiveFailures, 5);
    $second = $disable->execute($endpoint, DisableReason::ConsecutiveFailures, 5);

    expect([$first, $second])->toBe([true, false]);
    Event::assertDispatchedTimes(EndpointDisabled::class, 1);
});

it('a_success_resets_consecutive_failures', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $endpoint = outboundEndpoint(attributes: ['consecutive_failures' => 4]);

    attempt(Webhooks::send($endpoint, 'invoice.paid', ['n' => 1]));

    expect($endpoint->refresh()->consecutive_failures)->toBe(0)
        ->and($endpoint->last_success_at)->not->toBeNull();

    $endpoint->forceFill(['consecutive_failures' => 3, 'enabled' => false])->save();
    Webhooks::outbound()->enable($endpoint);
    expect($endpoint->refresh()->consecutive_failures)->toBe(0);
});

it('a_redelivery_opens_a_new_series_with_the_same_id', function (): void {
    Event::fake([DeliverySucceeded::class]);
    Http::fake(['*' => Http::response('ok')]);
    $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]));

    attempt(Webhooks::outbound()->redeliver($delivery));

    $ids = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('webhook-id')[0])->unique();
    expect($ids)->toHaveCount(1)
        ->and($delivery->series)->toBe(2)
        ->and($delivery->attempts)->toBe(1)
        ->and(OutboundAttempt::query()->orderBy('id')->pluck('manual')->all())->toBe([false, true]);
    Event::assertDispatchedTimes(DeliverySucceeded::class, 2);
});

it('redelivering_an_in_flight_delivery_throws', function (): void {
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]);

    expect(fn () => Webhooks::outbound()->redeliver($delivery))->toThrow(DeliveryInProgressException::class);

    $delivery->forceFill(['status' => OutboundStatus::Failed, 'body' => null, 'payload_pruned_at' => CarbonImmutable::now()])->save();
    expect(fn () => Webhooks::outbound()->redeliver($delivery))->toThrow(PayloadPrunedException::class);

    $disabled = OutboundDelivery::factory()->create(['endpoint_id' => outboundEndpoint(attributes: ['enabled' => false])->getKey()]);
    expect(fn () => Webhooks::outbound()->redeliver($disabled))->toThrow(EndpointNotAcceptingException::class);
});

it('fires the delivery events', function (): void {
    Event::fake([DeliverySucceeded::class, DeliveryFailed::class]);
    Http::fake(['*' => Http::sequence()->push('down', 503)->push('ok')]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['n' => 1]);

    attempt($delivery);
    Event::assertDispatched(DeliveryFailed::class, fn (DeliveryFailed $event): bool => ! $event->final && $event->nextAttemptAt?->toDateTimeString() === '2026-11-08 12:01:00');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    attempt($delivery);
    Event::assertDispatched(DeliverySucceeded::class, fn (DeliverySucceeded $event): bool => $event->attempt->number === 2 && $event->delivery->is($delivery));
});

it('reads the throttle state through the rate-limits fake', function (): void {
    $limits = RateLimits::fake();
    Http::fake(['*' => Http::response('ok')]);

    attempt(Webhooks::send($endpoint = outboundEndpoint(), 'invoice.paid', ['n' => 1]));

    $limits->assertAllowed('webhooks:out:'.$endpoint->getKey());
});
