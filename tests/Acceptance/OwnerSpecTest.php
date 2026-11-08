<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutgoingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Events\EndpointDisabled;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Events\WebhookRejected;
use RoundlyConsulting\Webhooks\Exceptions\DestinationBlockedException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;

/**
 * The owner's brief, case by case (O-1 … O-16). Each name is the plan's.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// O-1 — invalid signature → 401, no delivery event
it('an_invalid_signature_answers_401_and_queues_nothing', function (): void {
    Queue::fake();
    Event::fake([WebhookReceived::class, WebhookRejected::class]);
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->tamper()->send()
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'invalid_signature', 'status' => 401]);

    expect(InboundDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertDispatchedTimes(WebhookRejected::class, 1);
});

// O-2 — same idempotency key twice → one event
it('the_same_delivery_id_twice_makes_one_event', function (): void {
    Queue::fake();
    $endpoint = inboundEndpoint();
    $request = SignedWebhookRequest::for($endpoint)->id('msg_same')->payload(['type' => 'invoice.paid']);

    $request->send()->assertStatus(202);
    $request->send()->assertStatus(200)->assertExactJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
    Queue::assertPushed(ProcessInboundDelivery::class, 1);
});

it('the_same_idempotency_key_header_twice_makes_one_event', function (): void {
    Queue::fake();
    // A custom scheme without a delivery id of its own: the Idempotency-Key header is the key.
    Webhooks::schemes()->extend('bare', static fn () => new class implements SignatureScheme
    {
        public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
        {
            return new VerifiedWebhook(null, null, null, false, SecretSlot::Current);
        }
    });
    $endpoint = inboundEndpoint(['signature_scheme' => 'bare']);
    $post = fn () => $this->call('POST', $endpoint->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => '"order-42"'], '{"type":"order.paid"}');

    $post()->assertStatus(202);
    $post()->assertStatus(200);

    expect(InboundDelivery::query()->count())->toBe(1)
        ->and(InboundDelivery::query()->value('idempotency_key'))->toBe('order-42');
    Queue::assertPushed(ProcessInboundDelivery::class, 1);
});

// O-3 — stale timestamp rejected
it('a_stale_timestamp_is_rejected', function (): void {
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->staleBy(301)->send()->assertStatus(401)->assertJson(['code' => 'stale_timestamp']);
    expect(InboundDelivery::query()->count())->toBe(0);
});

it('a_timestamp_too_far_in_the_future_is_rejected', function (): void {
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->at(CarbonImmutable::now()->addSeconds(301))->send()->assertStatus(401)->assertJson(['code' => 'stale_timestamp']);
});

it('a_timestamp_inside_the_tolerance_is_accepted', function (): void {
    Queue::fake();
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->staleBy(299)->send()->assertStatus(202);
    SignedWebhookRequest::for($endpoint)->at(CarbonImmutable::now()->addSeconds(299))->send()->assertStatus(202);
});

// O-4 — payload over the limit → 413
it('a_payload_over_the_limit_answers_413', function (): void {
    config()->set('webhooks.inbound.max_payload_bytes', 100);
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->body('{"pad":"'.str_repeat('x', 200).'"}')->send()
        ->assertStatus(413)->assertJson(['code' => 'payload_too_large']);
    expect(InboundDelivery::query()->count())->toBe(0);
});

it('a_chunked_payload_over_the_limit_answers_413', function (): void {
    config()->set('webhooks.inbound.max_payload_bytes', 100);
    $endpoint = inboundEndpoint();
    $data = SignedWebhookRequest::for($endpoint)->body('{"pad":"'.str_repeat('x', 200).'"}')->toRequest();

    // No Content-Length (a chunked body): the pipeline reads at most limit + 1 bytes.
    $request = Request::create($data->uri, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $data->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $data->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $data->headers['webhook-signature'],
    ], $data->body);
    $request->headers->remove('Content-Length');

    expect(Webhooks::inbound()->receive($endpoint->url_key, $request)->getStatusCode())->toBe(413);
});

// O-5 — rotation grace (inbound + outbound)
it('old_and_new_secrets_verify_during_the_grace_period', function (): void {
    Queue::fake();
    $endpoint = inboundEndpoint();
    $rotated = Webhooks::inbound()->rotateSecret($endpoint, CarbonImmutable::now()->addHour());

    SignedWebhookRequest::for($endpoint->refresh())->signedWithPrevious()->send()->assertStatus(202);
    SignedWebhookRequest::for($endpoint)->secret($rotated->secret)->send()->assertStatus(202);

    expect(InboundDelivery::query()->pluck('signature_slot')->map->value->all())->toEqualCanonicalizing(['previous', 'current']);

    // Outbound: both secrets sign during the grace — two v1 entries, the new one first.
    publicDns();
    $outbound = outboundEndpoint();
    $new = Webhooks::outbound()->rotateSecret($outbound, CarbonImmutable::now()->addHour())->secret;
    Http::fake(['*' => Http::response('ok')]);
    attempt(Webhooks::send($outbound->refresh(), 'invoice.paid', ['invoice' => 42]));

    Http::assertSent(function (ClientRequest $request) use ($new): bool {
        $entries = explode(' ', $request->header('webhook-signature')[0]);

        return count($entries) === 2
            && $entries[0] === documentedSignature($new, $request->header('webhook-id')[0], $request->header('webhook-timestamp')[0], $request->body())
            && $entries[1] === documentedSignature(V2_SECRET, $request->header('webhook-id')[0], $request->header('webhook-timestamp')[0], $request->body());
    });
});

it('only_the_new_secret_verifies_after_the_grace_period', function (): void {
    Queue::fake();
    $endpoint = inboundEndpoint();
    $rotated = Webhooks::inbound()->rotateSecret($endpoint, CarbonImmutable::now()->addHour());
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour()->addSecond());

    SignedWebhookRequest::for($endpoint->refresh())->signedWithPrevious()->send()->assertStatus(401);
    SignedWebhookRequest::for($endpoint)->secret($rotated->secret)->send()->assertStatus(202);

    publicDns();
    $outbound = outboundEndpoint();
    Webhooks::outbound()->rotateSecret($outbound, CarbonImmutable::now()->addMinute());
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
    Http::fake(['*' => Http::response('ok')]);
    attempt(Webhooks::send($outbound->refresh(), 'invoice.paid', ['invoice' => 42]));

    Http::assertSent(fn (ClientRequest $request): bool => count(explode(' ', $request->header('webhook-signature')[0])) === 1);
});

// O-6 — unknown and disabled keys → 404
it('an_unknown_key_answers_404', function (): void {
    $this->postJson('/webhooks/'.str_repeat('A', 32), [])->assertStatus(404)->assertJson(['code' => 'not_found']);
});

it('a_disabled_endpoint_answers_404', function (): void {
    $endpoint = InboundEndpoint::factory()->withSecret(V2_SECRET)->disabled()->create();

    postSigned($endpoint)->assertStatus(404)->assertJson(['code' => 'not_found']);
});

it('a_soft_deleted_endpoint_answers_404', function (): void {
    $endpoint = inboundEndpoint();
    $endpoint->delete();

    postSigned($endpoint)->assertStatus(404);
});

it('unknown_and_disabled_answer_the_same_body', function (): void {
    $disabled = InboundEndpoint::factory()->withSecret(V2_SECRET)->disabled()->create();
    $unknown = InboundEndpoint::factory()->withSecret(V2_SECRET)->make(['url_key' => str_repeat('Z', 32)]);

    $a = postSigned($disabled);
    $b = postSigned($unknown);

    expect($a->getContent())->toBe($b->getContent())
        ->and($a->getStatusCode())->toBe($b->getStatusCode())
        ->and($a->headers->get('Content-Type'))->toBe($b->headers->get('Content-Type'));
});

// O-7 — outbound signature verifiable with the documented algorithm
it('an_outbound_signature_verifies_with_the_documented_algorithm', function (): void {
    Queue::fake();
    publicDns();
    $endpoint = outboundEndpoint();
    Http::fake(['*' => Http::response('ok')]);

    attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]));

    Http::assertSent(function (ClientRequest $request): bool {
        $id = $request->header('webhook-id')[0];
        $timestamp = $request->header('webhook-timestamp')[0];

        return str_starts_with($id, 'msg_')
            && $timestamp === (string) CarbonImmutable::now()->getTimestamp()
            && $request->header('webhook-signature')[0] === documentedSignature(V2_SECRET, $id, $timestamp, $request->body())
            && $request->header('Content-Type')[0] === 'application/json';
    });
});

it('the_default_scheme_matches_the_published_vectors', function (): void {
    /** @var array{vectors: list<array{secrets: list<string>, id: string, timestamp: string, body: string, signature: string}>} $contract */
    $contract = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/contract/default-scheme.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($contract['vectors'] as $vector) {
        $headers = (new DefaultScheme)->sign(
            new OutgoingWebhook($vector['id'], CarbonImmutable::createFromTimestampUTC((int) $vector['timestamp']), $vector['body'], 'test'),
            new EndpointSecrets($vector['secrets'], app(MacEngine::class)),
        );

        expect($headers->headers['webhook-signature'])->toBe($vector['signature'])
            ->and(documentedSignature($vector['secrets'][0], $vector['id'], $vector['timestamp'], $vector['body']))->toBe(explode(' ', $vector['signature'])[0]);
    }
});

// O-8 — retries with backoff on 500
it('a_500_is_retried_with_backoff', function (): void {
    Queue::fake();
    publicDns();
    $endpoint = outboundEndpoint();
    Http::fake(['*' => Http::response('down', 500)]);
    $delivery = Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]);

    $expected = [60, 240, 960, 3840, 15360, 32400, 32400];

    foreach ($expected as $delay) {
        $before = CarbonImmutable::now();
        attempt($delivery);

        expect($delivery->status)->toBe(OutboundStatus::Retrying)
            ->and($delivery->next_attempt_at?->getTimestamp())->toBe($before->addSeconds($delay)->getTimestamp());

        CarbonImmutable::setTestNow($delivery->next_attempt_at);

        if ($delivery->dispatch_token === null) {
            Webhooks::outbound()->dispatchDue();   // long delays go through the sweep
        }
    }

    attempt($delivery);

    expect($delivery->status)->toBe(OutboundStatus::Failed)
        ->and($delivery->attempts)->toBe(8)
        ->and(OutboundAttempt::query()->where('delivery_id', $delivery->getKey())->count())->toBe(8);
});

// O-9 — Retry-After on 429
it('a_429_retry_after_sets_the_next_attempt', function (): void {
    Queue::fake();
    publicDns();
    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '600'])]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42]);

    attempt($delivery);

    expect($delivery->next_attempt_at?->getTimestamp())->toBe(CarbonImmutable::now()->addSeconds(600)->getTimestamp())
        ->and(OutboundAttempt::query()->value('retry_after_seconds'))->toBe(600);
});

it('a_429_retry_after_sets_the_next_attempt_http_date', function (): void {
    Queue::fake();
    publicDns();
    $at = CarbonImmutable::now()->addMinutes(30);
    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => $at->toRfc7231String()])]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42]);

    attempt($delivery);

    expect($delivery->next_attempt_at?->getTimestamp())->toBe($at->getTimestamp());
});

// O-10 — auto-disable after N failures
it('an_endpoint_is_disabled_after_n_consecutive_failures', function (): void {
    Queue::fake();
    Event::fake([EndpointDisabled::class]);
    config()->set('webhooks.outbound.disable_after', 3);
    config()->set('webhooks.outbound.max_attempts', 1);
    publicDns();
    $endpoint = outboundEndpoint();
    Http::fake(['*' => Http::response('down', 500)]);

    $pending = null;

    foreach (range(1, 3) as $i) {
        $delivery = Webhooks::send($endpoint->refresh(), 'invoice.paid', ['n' => $i]);
        $pending ??= Webhooks::send($endpoint, 'invoice.paid', ['n' => 'waiting']);
        attempt($delivery);
    }

    expect($endpoint->refresh()->enabled)->toBeFalse()
        ->and($endpoint->disabled_reason)->toBe(DisableReason::ConsecutiveFailures)
        ->and($pending?->refresh()->status)->toBe(OutboundStatus::Cancelled);
    Event::assertDispatchedTimes(EndpointDisabled::class, 1);
});

// O-11 — SSRF: private IP
it('a_private_ip_url_is_blocked', function (string $url): void {
    expect(fn () => Webhooks::outbound()->create($url))->toThrow(DestinationBlockedException::class);
    expect(OutboundEndpoint::query()->count())->toBe(0);
})->with(['https://10.0.0.5/in', 'https://192.168.1.1/in', 'https://127.0.0.1/in', 'https://[::1]/in', 'https://[fd00::1]/in']);

// O-12 — metadata IP
it('the_metadata_ip_is_blocked', function (string $url): void {
    expect(fn () => Webhooks::outbound()->create($url))->toThrow(DestinationBlockedException::class);
})->with(['http://169.254.169.254/latest/meta-data' => 'https://169.254.169.254/latest/meta-data', 'https://[fd00:ec2::254]/', 'https://100.100.100.200/']);

// O-13 — a DNS name resolving to a private IP
it('a_name_resolving_to_a_private_ip_is_blocked', function (): void {
    publicDns(['internal.example.com' => '10.1.2.3']);

    expect(fn () => Webhooks::outbound()->create('https://internal.example.com/in'))->toThrow(DestinationBlockedException::class);

    // …and on every attempt: a name that turned private since it was saved sends nothing.
    Queue::fake();
    Http::fake();
    $endpoint = outboundEndpoint('https://hooks.example.com/in');
    publicDns(['hooks.example.com' => '10.9.9.9']);

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]));

    Http::assertNothingSent();
    expect($delivery->status)->toBe(OutboundStatus::Failed)
        ->and($delivery->last_error_code)->toBe(AttemptError::Blocked);
});

it('one_private_answer_among_public_ones_blocks_the_name', function (): void {
    publicDns(['mixed.example.com' => ['93.184.216.34', '10.0.0.7']]);

    expect(fn () => Webhooks::outbound()->create('https://mixed.example.com/in'))->toThrow(DestinationBlockedException::class);
});

// O-14 — a redirect to a private IP
it('a_redirect_to_a_private_ip_is_blocked', function (): void {
    Queue::fake();
    config()->set('webhooks.outbound.follow_redirects', true);
    publicDns();
    Http::fake(['https://hooks.example.com/*' => Http::response('', 307, ['Location' => 'https://10.0.0.1/steal'])]);

    $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42]));

    Http::assertSentCount(1);
    expect($delivery->status)->toBe(OutboundStatus::Failed)
        ->and($delivery->last_error_code)->toBe(AttemptError::Blocked);
});

it('a_redirect_is_not_followed_by_default', function (): void {
    Queue::fake();
    publicDns();
    Http::fake(['*' => Http::response('', 307, ['Location' => 'https://other.example.com/in'])]);

    $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42]));

    Http::assertSentCount(1);
    expect($delivery->status)->toBe(OutboundStatus::Retrying)
        ->and($delivery->last_error_code)->toBe(AttemptError::RedirectNotFollowed);
});

// O-15 — throttling per endpoint (outbound and inbound)
it('deliveries_are_throttled_per_endpoint', function (): void {
    Queue::fake();
    $limits = RateLimits::fake();
    publicDns();
    Http::fake(['*' => Http::response('ok')]);
    $a = outboundEndpoint(attributes: ['rate_limit_per_minute' => 2]);
    $b = outboundEndpoint('https://other.example.com/in', attributes: ['rate_limit_per_minute' => 2]);

    $sent = collect(range(1, 3))->map(fn (int $i) => attempt(Webhooks::send($a, 'invoice.paid', ['n' => $i])));
    $other = attempt(Webhooks::send($b, 'invoice.paid', ['n' => 1]));

    $third = $sent->last();
    expect($sent->take(2)->every(fn (OutboundDelivery $d): bool => $d->status === OutboundStatus::Succeeded))->toBeTrue()
        ->and($third->status)->toBe(OutboundStatus::Pending)
        ->and($third->attempts)->toBe(0)
        ->and(OutboundAttempt::query()->where('delivery_id', $third->getKey())->count())->toBe(0)
        ->and($third->next_attempt_at?->getTimestamp())->toBeGreaterThan(CarbonImmutable::now()->getTimestamp())
        ->and($other->status)->toBe(OutboundStatus::Succeeded);
    $limits->assertDeferred('webhooks:out:'.$a->getKey());
    Http::assertSentCount(3);
});

it('inbound_rate_limits_are_per_endpoint', function (): void {
    Queue::fake();
    $limited = inboundEndpoint(['rate_limit_per_minute' => 2]);
    $other = inboundEndpoint();

    postSigned($limited)->assertStatus(202);
    postSigned($limited)->assertStatus(202);
    postSigned($limited)->assertStatus(429)->assertHeader('Retry-After')->assertJson(['code' => 'rate_limited']);
    postSigned($other)->assertStatus(202);
});

// O-16 — prune command
it('the_prune_command_removes_expired_payloads_and_deliveries', function (): void {
    $old = InboundDelivery::factory()->create(['received_at' => CarbonImmutable::now()->subDays(31)]);
    $ancient = InboundDelivery::factory()->create(['received_at' => CarbonImmutable::now()->subDays(91)]);
    $fresh = InboundDelivery::factory()->create(['received_at' => CarbonImmutable::now()->subDay()]);
    $sent = OutboundDelivery::factory()->create(['created_at' => CarbonImmutable::now()->subDays(31)]);

    Artisan::call('webhooks:prune', ['--dry-run' => true]);

    expect($old->refresh()->payload)->not->toBeNull()
        ->and(InboundDelivery::query()->count())->toBe(3);

    Artisan::call('webhooks:prune');

    expect($old->refresh()->payload)->toBeNull()
        ->and($old->payload_pruned_at)->not->toBeNull()
        ->and($fresh->refresh()->payload)->not->toBeNull()
        ->and(InboundDelivery::withTrashed()->find($ancient->getKey()))->toBeNull()
        ->and($sent->refresh()->body)->toBeNull();
});
