<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Events\WebhookProcessingFailed;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Http\Resources\InboundDeliveryResource;
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
        ->and($delivery->last_error_message)->toBe('RuntimeException');
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

it('inbound_webhook_helpers_read_payload_and_headers', function (): void {
    Event::fake([WebhookReceived::class]);
    $team = team();
    $endpoint = inboundEndpoint(['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);

    postSigned($endpoint, ['type' => 'invoice.paid', 'data' => ['invoice' => ['id' => 42]]], fn ($request) => $request->withHeader('User-Agent', 'Sender/1.0'))->assertStatus(202);

    Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $event) use ($team): bool {
        $webhook = $event->webhook;

        expect($webhook->get('data.invoice.id'))->toBe(42)
            ->and($webhook->get('data.missing', 'fallback'))->toBe('fallback')
            ->and($webhook->header('User-Agent'))->toBe('Sender/1.0')
            ->and($webhook->header('x-not-stored'))->toBeNull()
            ->and($webhook->owner()?->is($team))->toBeTrue();

        return true;
    });

    $appLevel = new InboundWebhook('x', inboundEndpoint(), null, [], [], CarbonImmutable::now(), false, 1, 1);
    expect($appLevel->owner())->toBeNull();
});

it('a_closure_handler_runs', function (): void {
    $seen = [];
    Webhooks::handlers()->register('inline', function (InboundWebhook $webhook) use (&$seen): void {
        $seen[] = $webhook->get('data.id');
    });

    postSigned(inboundEndpoint(['handler' => 'inline']))->assertStatus(202);

    expect($seen)->toBe([42])
        ->and(Webhooks::handlers()->has('inline'))->toBeTrue()
        ->and(InboundDelivery::query()->sole()->status)->toBe(InboundStatus::Processed);
});

it('never stores the message of a handler exception, which may carry payload data', function (): void {
    Queue::fake();
    Webhooks::handlers()->register('leaky', function (InboundWebhook $webhook): void {
        DB::table('missing_table')->insert(['card' => $webhook->get('card')]);
    });
    $endpoint = inboundEndpoint(['handler' => 'leaky']);
    postSigned($endpoint, ['type' => 'card.added', 'card' => '4111111111111111'])->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();
    $job = new ProcessInboundDelivery($delivery->getKey());

    try {
        app()->call([$job, 'handle']);
    } catch (QueryException $exception) {
        $job->failed($exception);
    }

    $delivery->refresh();
    $rendered = json_encode((new InboundDeliveryResource($delivery))->resolve(request()), JSON_THROW_ON_ERROR);

    // The SQL with its bound values, the connection and the database stay in report() only.
    expect($delivery->last_error_message)->toBe('QueryException (HY000)')
        ->and($rendered)->not->toContain('4111111111111111')->not->toContain('missing_table')
        ->and($rendered)->toContain('QueryException (HY000)');

    Webhooks::handlers()->forget('leaky');
});

it('a_late_failing_copy_of_a_finished_or_superseded_run_changes_nothing', function (): void {
    postSigned(inboundEndpoint(['handler' => 'billing']))->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();
    expect($delivery->status)->toBe(InboundStatus::Processed);
    Event::fake([WebhookProcessingFailed::class]);

    // A released duplicate copy exhausting its tries after the run already succeeded…
    (new ProcessInboundDelivery($delivery->getKey(), 1))->failed(new MaxAttemptsExceededException('x'));
    expect($delivery->refresh()->status)->toBe(InboundStatus::Processed)->and($delivery->last_error_code)->toBeNull();

    // …or a copy of run 1 failing while run 2 (a replay) is under way.
    $delivery->forceFill(['runs' => 2, 'status' => InboundStatus::Processing])->save();
    (new ProcessInboundDelivery($delivery->getKey(), 1))->failed(new MaxAttemptsExceededException('x'));
    expect($delivery->refresh()->status)->toBe(InboundStatus::Processing);

    Event::assertNotDispatched(WebhookProcessingFailed::class);

    // The current run's own failure still counts.
    (new ProcessInboundDelivery($delivery->getKey(), 2))->failed(new RuntimeException('down'));
    expect($delivery->refresh()->status)->toBe(InboundStatus::Failed);
    Event::assertDispatched(WebhookProcessingFailed::class);
});

it('keeps the payload of a delivery still waiting to run, and fails a pruned one', function (): void {
    Queue::fake();
    Event::fake([WebhookReceived::class, WebhookProcessingFailed::class]);
    postSigned(inboundEndpoint(['handler' => 'billing']))->assertStatus(202);
    $waiting = InboundDelivery::query()->sole();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(config('webhooks.payloads.retention_days') + 1));

    // Retention never clears a delivery whose handler has not run yet…
    expect(Webhooks::prune()->inboundPayloads)->toBe(0)
        ->and($waiting->refresh()->payload)->not->toBeNull();

    // …and a run that still meets a cleared payload fails, never handing the handler [].
    $waiting->forceFill(['payload' => null, 'payload_pruned_at' => CarbonImmutable::now()])->save();
    app()->call([new ProcessInboundDelivery($waiting->getKey()), 'handle']);

    expect($waiting->refresh()->status)->toBe(InboundStatus::Failed)
        ->and($waiting->last_error_code)->toBe('payload_pruned');
    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertDispatched(WebhookProcessingFailed::class, fn (WebhookProcessingFailed $event): bool => $event->errorCode === 'payload_pruned');
    CarbonImmutable::setTestNow();
});
