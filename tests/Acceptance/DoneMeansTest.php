<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Events\DeliverySucceeded;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models\HandledWebhook;

/**
 * The owner's "Done means": a test app with an inbound endpoint whose verified deliveries queue a
 * handler exactly once, and an outbound endpoint that delivers signed events with retries and
 * SSRF protection — on a real (database) queue, drained by a real worker.
 */
beforeEach(function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function drainQueue(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
}

it('done_means_an_inbound_endpoint_queues_its_handler_exactly_once', function (): void {
    Event::fake([WebhookReceived::class]);
    $team = team();
    $created = Webhooks::for($team)->inbound()->create(handler: 'billing');
    $request = SignedWebhookRequest::for($created->endpoint)
        ->secret($created->secret)
        ->id('msg_01JCDEF0000000000000000000')
        ->body('{"type":"invoice.paid","timestamp":"2026-11-08T12:00:00Z","data":{"invoice":42}}');

    $request->send()->assertStatus(202)->assertJsonStructure(['status', 'id']);
    $request->send()->assertStatus(200)->assertJson(['status' => 'duplicate']);   // the sender's retry
    SignedWebhookRequest::for($created->endpoint)->secret($created->secret)->tamper()->send()->assertStatus(401);

    // Nothing ran inside the requests.
    expect(HandledWebhook::query()->count())->toBe(0);

    // A double dispatch on top.
    $delivery = InboundDelivery::query()->sole();
    dispatch(new ProcessInboundDelivery($delivery->getKey(), 1));

    drainQueue();

    expect(HandledWebhook::query()->count())->toBe(1)
        ->and(HandledWebhook::query()->sole()->type)->toBe('invoice.paid')
        ->and($delivery->refresh()->status)->toBe(InboundStatus::Processed);
    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('done_means_an_outbound_endpoint_delivers_signed_events_with_retries_and_ssrf_protection', function (): void {
    Event::fake([DeliverySucceeded::class]);
    $dns = publicDns(['rebound.example.com' => '93.184.216.99']);
    $team = team();
    $created = Webhooks::for($team)->outbound()->create('https://hooks.example.com/in', events: ['invoice.*']);
    $rebound = Webhooks::for($team)->outbound()->create('https://rebound.example.com/in', events: ['invoice.*']);
    $dns->resolve('rebound.example.com', '10.1.2.3');   // DNS rebinding after the endpoint was saved

    $seen = [];
    $answers = [Http::response('down', 500), Http::response('slow down', 429, ['Retry-After' => '120']), Http::response('ok', 200)];
    Http::fake(function (ClientRequest $request, array $options) use (&$seen, &$answers) {
        $seen[] = ['request' => $request, 'options' => $options, 'at' => CarbonImmutable::now()];

        return array_shift($answers);
    });

    $deliveries = Webhooks::for($team)->broadcast('invoice.paid', ['invoice' => 42]);
    expect($deliveries)->toHaveCount(2);

    drainQueue();                                                    // attempt 1: 500
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
    drainQueue();                                                    // attempt 2: 429, Retry-After 120
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(241));
    drainQueue();                                                    // attempt 3: 200

    $delivery = $created->endpoint->deliveries()->sole();
    $blocked = $rebound->endpoint->deliveries()->sole();

    expect($delivery->status)->toBe(OutboundStatus::Succeeded)
        ->and($delivery->attempts)->toBe(3)
        ->and(OutboundAttempt::query()->where('delivery_id', $delivery->getKey())->orderBy('number')->pluck('response_status')->all())->toBe([500, 429, 200])
        ->and($blocked->status)->toBe(OutboundStatus::Failed)
        ->and($blocked->last_error_code)->toBe(AttemptError::Blocked)
        ->and($seen)->toHaveCount(3);

    // Retries: 60 s, then the backoff (240 s ≥ the receiver's Retry-After of 120 s).
    expect($seen[1]['at']->diffInSeconds($seen[0]['at'], true))->toBeGreaterThanOrEqual(60.0)
        ->and($seen[2]['at']->diffInSeconds($seen[1]['at'], true))->toBeGreaterThanOrEqual(120.0);

    foreach ($seen as $call) {
        /** @var ClientRequest $request */
        $request = $call['request'];

        // Pinned to the vetted address, no proxy …
        expect($call['options']['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:443:93.184.216.34'])
            ->and($call['options']['proxy'])->toBe('')
            ->and($request->url())->toBe('https://hooks.example.com/in')
            // … and verifiable with the documented algorithm by an independent verifier.
            ->and($request->header('webhook-signature')[0])->toBe(documentedSignature($created->secret, $request->header('webhook-id')[0], $request->header('webhook-timestamp')[0], $request->body()));
    }

    Event::assertDispatchedTimes(DeliverySucceeded::class, 1);
});
