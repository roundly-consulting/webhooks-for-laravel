<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Sentinel\Contracts\IdempotencyStore;
use RoundlyConsulting\Sentinel\Facades\Sentinel;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\Actions\Inbound\AcceptInboundDeliveryAction;
use RoundlyConsulting\Webhooks\Contracts\PayloadSanitizer;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\DataTransferObjects\AcceptedDelivery;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutgoingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\SanitizeContext;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Events\WebhookRejected;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Support\InboundRoute;
use RoundlyConsulting\Webhooks\Support\KeyRedactingSanitizer;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Webhooks\Tests\Support\FlakyIdempotencyStore;

beforeEach(function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A scheme that accepts anything and reports an unsigned (or no) delivery id — the shape of a
 * GitHub-style header id or a bare Idempotency-Key.
 */
function unsignedScheme(?string $idHeader = 'x-delivery'): void
{
    Webhooks::schemes()->extend('unsigned', static fn () => new class($idHeader) implements SignatureScheme
    {
        public function __construct(private readonly ?string $idHeader) {}

        public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
        {
            return new VerifiedWebhook($this->idHeader === null ? null : $request->header($this->idHeader), null, null, false, SecretSlot::Current);
        }
    });
}

/**
 * @param  array<string, string>  $headers
 */
function rawPost(string $uri, string $body, array $headers = ['CONTENT_TYPE' => 'application/json']): TestResponse
{
    return test()->call('POST', $uri, [], [], [], $headers, $body);
}

it('the_raw_body_bytes_are_what_is_verified', function (): void {
    $endpoint = inboundEndpoint();
    $body = "{ \"b\" : 1,\n  \"a\":\"\\u00e9\" }  ";

    SignedWebhookRequest::for($endpoint)->body($body)->send()->assertStatus(202);

    // The same JSON re-serialised is a different message.
    $signed = SignedWebhookRequest::for($endpoint)->body($body)->toRequest();
    rawPost($signed->uri, '{"b":1,"a":"é"}', [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ])->assertStatus(401);

    expect(InboundDelivery::query()->sole()->payload_digest)->toBe(Crypto::digest()->hex($body));
});

it('a_malformed_timestamp_is_rejected', function (string $timestamp): void {
    $endpoint = inboundEndpoint();
    $signed = SignedWebhookRequest::for($endpoint)->toRequest();

    rawPost($signed->uri, $signed->body, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ])->assertStatus(401)->assertJson(['code' => 'invalid_timestamp']);
})->with(['word' => 'now', 'signed' => '-1794139200', 'fraction' => '1794139200.5', '13 digits' => '1794139200000']);

it('refuses missing signature headers by name', function (string $missing, string $code): void {
    $endpoint = inboundEndpoint();
    $signed = SignedWebhookRequest::for($endpoint)->toRequest();
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ];
    unset($headers[$missing]);

    rawPost($signed->uri, $signed->body, $headers)->assertStatus(401)->assertJson(['code' => $code]);
})->with([
    ['HTTP_WEBHOOK_SIGNATURE', 'missing_signature'],
    ['HTTP_WEBHOOK_TIMESTAMP', 'missing_timestamp'],
    ['HTTP_WEBHOOK_ID', 'missing_id'],
]);

it('any_matching_v1_signature_accepts', function (): void {
    $endpoint = inboundEndpoint();
    $signed = SignedWebhookRequest::for($endpoint)->toRequest();
    $valid = $signed->headers['webhook-signature'];

    rawPost($signed->uri, $signed->body, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => 'v1,AAAA v1,not-base64! '.$valid,
    ])->assertStatus(202);
});

it('unknown_signature_versions_are_ignored', function (): void {
    $endpoint = inboundEndpoint();
    $signed = SignedWebhookRequest::for($endpoint)->toRequest();
    $mac = substr($signed->headers['webhook-signature'], 3);

    rawPost($signed->uri, $signed->body, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => 'v1a,'.$mac,
    ])->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
});

it('an_oversized_signature_header_is_refused', function (string $header): void {
    $endpoint = inboundEndpoint();
    $signed = SignedWebhookRequest::for($endpoint)->toRequest();

    rawPost($signed->uri, $signed->body, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $header.' '.$signed->headers['webhook-signature'],
    ])->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
})->with([
    'nine entries' => implode(' ', array_fill(0, 8, 'v1,AAAA')),
    'over 4 KB' => 'v1,'.str_repeat('A', 4100),
]);

it('accepts any visible ascii delivery id standard webhooks senders use', function (string $id): void {
    SignedWebhookRequest::for(inboundEndpoint())->id($id)->send()->assertStatus(202);

    expect(InboundDelivery::query()->sole()->external_id)->toBe($id);
})->with(['evt.123', 'urn:evt:1', '{0b0a7b1e-4c55-4b8e-9d0e-6f1c3f6a2b10}', 'a/b+c=d']);

it('refuses a delivery id with a space, a control byte or over 255 bytes', function (string $id): void {
    SignedWebhookRequest::for(inboundEndpoint())->id($id)->send()->assertStatus(401);
})->with(['msg 1', 'msg', str_repeat('a', 256)]);

it('rejections_render_problem_json_without_internals', function (): void {
    $response = SignedWebhookRequest::for(inboundEndpoint())->tamper()->send();

    expect($response->json())->toBe(['title' => 'The signature is not valid.', 'status' => 401, 'code' => 'invalid_signature'])
        ->and((string) $response->getContent())->not->toContain('\\')->not->toContain('Exception');
});

it('the_inbound_route_needs_no_csrf_token', function (): void {
    $route = Route::getRoutes()->getByName('webhooks.inbound');

    expect($route?->gatherMiddleware())->toBe([])
        ->and($route?->methods())->toBe(['POST']);
});

it('only_post_is_routed', function (): void {
    $endpoint = inboundEndpoint();

    $this->get($endpoint->url())->assertStatus(405);
    $this->put($endpoint->url())->assertStatus(405);
});

it('an_oversized_body_is_refused_before_verification', function (): void {
    config()->set('webhooks.inbound.max_payload_bytes', 50);
    $sentinel = Sentinel::fake();

    SignedWebhookRequest::for(inboundEndpoint())->body('{"pad":"'.str_repeat('x', 100).'"}')->send()->assertStatus(413);
    $this->call('POST', inboundEndpoint()->url(), [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'hello')->assertStatus(415);

    expect($sentinel->recorded('verifyWithSecrets'))->toBe([]);
});

it('macs_go_through_sentinel_and_its_fake_sees_them', function (): void {
    $sentinel = Sentinel::fake();
    $endpoint = inboundEndpoint();

    postSigned($endpoint)->assertStatus(202);

    // SignedWebhookRequest signed once, the pipeline verified once — the secret never recorded.
    expect($sentinel->recorded('signWithSecret'))->toHaveCount(1)
        ->and($sentinel->recorded('verifyWithSecrets'))->toHaveCount(1)
        ->and(serialize($sentinel->recorded('verifyWithSecrets')))->not->toContain(base64_decode(substr(V2_SECRET, 6)));

    // And the vectors still come out of the faked engine (V-3: current first, then previous).
    $macs = app(MacEngine::class)->sign('msg_01JCDEF0000000000000000000.1794139200.{"type":"invoice.paid","timestamp":"2026-11-08T12:00:00Z","data":{"invoice":42}}', [V3_SECRET, V2_SECRET], SecretEncoding::StandardWebhooks);
    expect(array_map(base64_encode(...), $macs))->toBe(['Nwl36Rvbn1xO29I4VEpYVwe7HJ3vZuLKGz6KoEVZDFI=', 'dBcYxACDimm+8xx9Ykx71LVUgk5H4STQXxAUHZ5xcNk=']);
});

it('unknown_keys_fire_no_event', function (): void {
    Event::fake([WebhookRejected::class]);

    $this->postJson('/webhooks/'.str_repeat('Q', 32), [])->assertStatus(404);
    $this->postJson('/webhooks/short', [])->assertStatus(404);
    Event::assertNotDispatched(WebhookRejected::class);

    postSigned(InboundEndpoint::factory()->disabled()->create())->assertStatus(404);
    Event::assertDispatched(WebhookRejected::class, fn (WebhookRejected $event): bool => $event->reason === RejectionReason::EndpointDisabled && $event->status === 404);
});

it('refuses a malformed key handed to receive() directly', function (): void {
    expect(Webhooks::inbound()->receive('../etc', Request::create('/'))->getStatusCode())->toBe(404);
});

it('a_long_idempotency_key_is_hashed', function (): void {
    unsignedScheme();
    $endpoint = inboundEndpoint(['signature_scheme' => 'unsigned']);
    $long = str_repeat('k', 300);

    rawPost($endpoint->url(), '{"a":1}', ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DELIVERY' => $long])->assertStatus(202);

    expect(InboundDelivery::query()->sole()->idempotency_key)->toBe('sha256:'.Crypto::digest()->hex($long));
});

it('a_blank_idempotency_key_is_refused', function (): void {
    unsignedScheme(null);
    $endpoint = inboundEndpoint(['signature_scheme' => 'unsigned']);

    rawPost($endpoint->url(), '{"a":1}', ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => '   '])->assertStatus(400)->assertJson(['code' => 'missing_id']);
    rawPost($endpoint->url(), '{"a":1}')->assertStatus(400);

    config()->set('webhooks.inbound.accept_idempotency_header', false);
    rawPost($endpoint->url(), '{"a":1}', ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k-1'])->assertStatus(400);
});

it('a_forged_idempotency_header_cannot_bypass_the_signed_id', function (): void {
    $endpoint = inboundEndpoint();
    $request = SignedWebhookRequest::for($endpoint)->id('msg_signed');

    $request->send()->assertStatus(202);
    $request->withHeader('Idempotency-Key', 'fresh-'.uniqid())->send()->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
});

it('the_same_key_with_another_body_answers_409', function (): void {
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->id('msg_x')->payload(['a' => 1])->send()->assertStatus(202);
    SignedWebhookRequest::for($endpoint)->id('msg_x')->payload(['a' => 2])->send()->assertStatus(409)->assertJson(['code' => 'idempotency_key_reused']);

    expect(InboundDelivery::query()->count())->toBe(1);
});

it('answers a duplicate with 409 when configured to', function (): void {
    config()->set('webhooks.inbound.on_duplicate', 'conflict');
    $request = SignedWebhookRequest::for(inboundEndpoint())->id('msg_dup');

    $request->send()->assertStatus(202);
    $request->send()->assertStatus(409)->assertJson(['code' => 'conflict']);
});

it('a_duplicate_while_the_first_is_stored_answers_409', function (): void {
    $endpoint = inboundEndpoint();
    $request = SignedWebhookRequest::for($endpoint)->id('msg_inflight');
    $digest = Crypto::digest()->hex($request->toRequest()->body);

    // The first request holds the key (a lease) while it is being stored.
    Sentinel::idempotency()->run('msg_inflight', 'webhooks:in:'.$endpoint->getKey(), function () use ($request): void {
        $request->send()->assertStatus(409)->assertJson(['code' => 'in_progress'])->assertHeader('Retry-After', '1');
    }, $digest);
});

it('a_duplicate_after_the_dedupe_ttl_is_still_one_delivery', function (): void {
    $endpoint = inboundEndpoint();
    $request = SignedWebhookRequest::for($endpoint)->id('msg_ttl');

    $request->send()->assertStatus(202);
    Sentinel::idempotency()->forget('msg_ttl', 'webhooks:in:'.$endpoint->getKey());   // the store forgot it
    $request->send()->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
});

it('a_delivery_stored_but_not_queued_is_queued_by_the_sweep', function (): void {
    $endpoint = inboundEndpoint();
    $request = SignedWebhookRequest::for($endpoint)->id('msg_lost');
    $request->send()->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();

    // As if the dispatch after the insert had failed.
    $delivery->forceFill(['status' => InboundStatus::Received, 'queued_at' => null])->save();
    Queue::fake();
    $request->send()->assertStatus(200);
    Queue::assertPushed(ProcessInboundDelivery::class, 1);
    expect($delivery->refresh()->status)->toBe(InboundStatus::Queued);

    $delivery->forceFill(['status' => InboundStatus::Received, 'queued_at' => null])->save();
    expect(Webhooks::outbound()->dispatchDue())->toBe(0);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
    expect(Webhooks::outbound()->dispatchDue())->toBe(1)
        ->and($delivery->refresh()->status)->toBe(InboundStatus::Queued)
        ->and($delivery->queued_at)->not->toBeNull();
});

it('a_replayed_body_under_a_new_unsigned_id_is_a_duplicate', function (): void {
    unsignedScheme();
    $endpoint = inboundEndpoint(['signature_scheme' => 'unsigned']);

    rawPost($endpoint->url(), '{"a":1}', ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DELIVERY' => 'gh-1'])->assertStatus(202);
    rawPost($endpoint->url(), '{"a":1}', ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DELIVERY' => 'gh-2'])->assertStatus(200)->assertJson(['status' => 'duplicate']);
    rawPost($endpoint->url(), '{"a":2}', ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DELIVERY' => 'gh-3'])->assertStatus(202);

    expect(InboundDelivery::query()->count())->toBe(2);
});

it('endpoint_overrides_beat_the_config', function (): void {
    $endpoint = Webhooks::inbound()->create(secret: V2_SECRET, options: new InboundEndpointOptions(
        maxPayloadBytes: 30, contentTypes: ['application/vnd.acme+json'], toleranceSeconds: 10,
    ))->endpoint;

    SignedWebhookRequest::for($endpoint)->body('{"pad":"'.str_repeat('x', 40).'"}')->withHeader('Content-Type', 'application/vnd.acme+json')->send()->assertStatus(413);
    SignedWebhookRequest::for($endpoint)->body('{"a":1}')->send()->assertStatus(415);
    SignedWebhookRequest::for($endpoint)->body('{"a":1}')->withHeader('Content-Type', 'application/vnd.acme+json')->staleBy(11)->send()->assertStatus(401);
    SignedWebhookRequest::for($endpoint)->body('{"a":1}')->withHeader('Content-Type', 'application/vnd.acme+json; charset=utf-8')->send()->assertStatus(202);
});

it('accepts application/*+json by default', function (): void {
    SignedWebhookRequest::for(inboundEndpoint())->withHeader('Content-Type', 'application/cloudevents+json')->send()->assertStatus(202);
});

it('rejections_do_not_touch_last_received_at', function (): void {
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->tamper()->send()->assertStatus(401);
    expect($endpoint->refresh()->last_received_at)->toBeNull();

    postSigned($endpoint)->assertStatus(202);
    expect($endpoint->refresh()->last_received_at?->toDateTimeString())->toBe('2026-11-08 12:00:00');
});

it('invalid_json_answers_400', function (string $body): void {
    SignedWebhookRequest::for(inboundEndpoint())->body($body)->send()->assertStatus(400)->assertJson(['code' => 'invalid_payload']);
})->with(['broken' => '{"a":', 'scalar' => '42', 'string' => '"hi"', 'too deep' => str_repeat('[', 70).str_repeat(']', 70)]);

it('payloads_decode_to_arrays_only', function (): void {
    SignedWebhookRequest::for(inboundEndpoint())->body('{"o":{"__class":"App\\\\Evil","n":12345678901234567890}}')->send()->assertStatus(202);

    $payload = InboundDelivery::query()->sole()->payload;

    expect($payload)->toBe(['o' => ['__class' => 'App\\Evil', 'n' => '12345678901234567890']]);
});

it('global_middleware_never_decodes_an_inbound_body', function (): void {
    $kernel = app(Kernel::class);
    $kernel->pushMiddleware(TrimStrings::class);
    $kernel->pushMiddleware(ConvertEmptyStringsToNull::class);
    $endpoint = inboundEndpoint();

    // The body is whitespace-padded: TrimStrings would change it — and break the signature.
    $body = '{"name":"  padded  ","empty":""}';
    SignedWebhookRequest::for($endpoint)->body($body)->send()->assertStatus(202);

    expect(InboundDelivery::query()->sole()->payload)->toBe(['name' => '  padded  ', 'empty' => '']);
});

it('keeps the host\'s input middleware on every post but an inbound key path', function (string $prefix, ?string $domain, string $url, bool $skipped): void {
    TrimStrings::flushState();
    ConvertEmptyStringsToNull::flushState();
    $kernel = app(Kernel::class);
    $kernel->pushMiddleware(TrimStrings::class);
    $kernel->pushMiddleware(ConvertEmptyStringsToNull::class);
    Route::post('{any}', static fn (Request $request) => response()->json($request->all()))->where('any', '.*');

    InboundRoute::skipBodyDecoding($prefix, $domain);

    $this->postJson($url, ['name' => '  x  ', 'blank' => ''])
        ->assertExactJson($skipped ? ['name' => '  x  ', 'blank' => ''] : ['name' => 'x', 'blank' => null]);
})->with([
    'no prefix, a host route' => ['', null, 'http://localhost/profile', false],
    'no prefix, a key' => ['', null, 'http://localhost/'.str_repeat('a', 32), true],
    'no prefix, a key-length path with a slash' => ['', null, 'http://localhost/a/'.str_repeat('a', 30), false],
    'a host route under the prefix' => ['hooks', null, 'http://localhost/hooks/settings', false],
    'a key under the prefix' => ['/hooks/', null, 'http://localhost/hooks/'.str_repeat('Ab1', 10).'xy', true],
    'a too-long key under the prefix' => ['hooks', null, 'http://localhost/hooks/'.str_repeat('a', 33), false],
    'another domain' => ['hooks', 'in.example.com', 'http://app.example.com/hooks/'.str_repeat('a', 32), false],
    'the route domain' => ['hooks', 'in.example.com', 'http://in.example.com/hooks/'.str_repeat('a', 32), true],
    'a domain parameter' => ['hooks', '{tenant}.in.example.com', 'http://acme.in.example.com/hooks/'.str_repeat('a', 32), true],
    'a domain parameter spanning labels' => ['hooks', '{tenant}.in.example.com', 'http://a.b.in.example.com/hooks/'.str_repeat('a', 32), false],
]);

it('the_sanitizer_redacts_before_storage_and_handlers', function (): void {
    postSigned(inboundEndpoint(), ['type' => 'user.created', 'user' => ['Password' => 'p4ss', 'nested' => ['api_key' => 'k']], 'token' => 't'])->assertStatus(202);

    expect(InboundDelivery::query()->sole()->payload)->toBe([
        'type' => 'user.created',
        'user' => ['Password' => '[redacted]', 'nested' => ['api_key' => '[redacted]']],
        'token' => '[redacted]',
    ]);
});

it('a_failing_sanitizer_stores_nothing', function (): void {
    app()->bind(PayloadSanitizer::class, fn () => new class implements PayloadSanitizer
    {
        public function sanitize(array $payload, SanitizeContext $context): array
        {
            throw new RuntimeException('broken');
        }
    });
    $endpoint = inboundEndpoint();

    postSigned($endpoint)->assertStatus(500)->assertJson(['code' => 'sanitizer_failed']);
    expect(InboundDelivery::query()->count())->toBe(0);

    // The key was given back: once the sanitizer works, the same delivery is accepted.
    app()->forgetInstance(PayloadSanitizer::class);
    app()->bind(PayloadSanitizer::class, KeyRedactingSanitizer::class);
    postSigned($endpoint)->assertStatus(202);
});

it('only_allowlisted_headers_are_stored', function (): void {
    config()->set('webhooks.inbound.store_headers', ['content-type', 'webhook-id', 'authorization', 'x-trace']);

    SignedWebhookRequest::for(inboundEndpoint())->withHeader('Authorization', 'Bearer secret')->withHeader('X-Trace', 'abc')->send()->assertStatus(202);

    $headers = InboundDelivery::query()->sole()->headers;
    expect(array_keys($headers ?? []))->toEqualCanonicalizing(['content-type', 'webhook-id', 'x-trace']);
});

it('payloads_are_encrypted_at_rest', function (): void {
    postSigned(inboundEndpoint(), ['type' => 'invoice.paid', 'marker' => 'plain-marker'])->assertStatus(202);

    $raw = InboundDelivery::query()->toBase()->value('payload');
    expect($raw)->toStartWith('enc:')->not->toContain('plain-marker');

    config()->set('webhooks.payloads.encrypt', false);
    postSigned(inboundEndpoint(), ['type' => 'invoice.paid', 'marker' => 'second'])->assertStatus(202);
    expect(InboundDelivery::query()->toBase()->pluck('payload')->last())->toStartWith('raw:')
        ->and(InboundDelivery::query()->get()->pluck('payload.marker')->all())->toBe(['plain-marker', 'second']);
});

it('rate_limited_rejections_fire_once_per_window', function (): void {
    Event::fake([WebhookRejected::class]);
    config()->set('webhooks.inbound.rate_limit.rejected_per_minute', 2);
    $endpoint = inboundEndpoint();

    foreach (range(1, 2) as $i) {
        SignedWebhookRequest::for($endpoint)->tamper()->send()->assertStatus(401);
    }

    foreach (range(1, 5) as $i) {
        SignedWebhookRequest::for($endpoint)->tamper()->send()->assertStatus(429)->assertHeader('Retry-After');
    }

    Event::assertDispatchedTimes(WebhookRejected::class, 3);
    Event::assertDispatched(WebhookRejected::class, fn (WebhookRejected $event): bool => $event->reason === RejectionReason::RateLimited);
});

it('bad_signatures_do_not_spend_the_senders_budget', function (): void {
    config()->set('webhooks.inbound.rate_limit.rejected_per_minute', 3);
    $endpoint = inboundEndpoint(['rate_limit_per_minute' => 5]);

    // A stranger on another IP floods bad signatures: only that IP is refused.
    foreach (range(1, 6) as $i) {
        test()->call('POST', $endpoint->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_WEBHOOK_ID' => 'x', 'HTTP_WEBHOOK_TIMESTAMP' => '1', 'HTTP_WEBHOOK_SIGNATURE' => 'v1,AAAA'], '{}');
    }

    postSigned($endpoint)->assertStatus(202);
});

it('a_host_route_can_receive_through_the_manager', function (): void {
    Route::post('/hooks/{key}', fn (Request $request, string $key) => Webhooks::inbound()->receive($key, $request));
    config()->set('webhooks.inbound.route.url_base', 'https://hooks.app.test/in/');
    $endpoint = inboundEndpoint();

    expect($endpoint->url())->toBe('https://hooks.app.test/in/'.$endpoint->url_key);

    $signed = SignedWebhookRequest::for($endpoint)->toRequest();
    rawPost('/hooks/'.$endpoint->url_key, $signed->body, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $signed->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $signed->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $signed->headers['webhook-signature'],
    ])->assertStatus(202);
});

it('an_unknown_scheme_answers_401', function (): void {
    $signDefault = fn ($request) => $request->signUsing(fn (OutgoingWebhook $message, EndpointSecrets $secrets) => (new DefaultScheme)->sign($message, $secrets));

    postSigned(inboundEndpoint(['signature_scheme' => 'nope']), tune: $signDefault)->assertStatus(401)->assertJson(['code' => 'unknown_scheme']);
});

it('accepted_delivery_round_trips_through_the_idempotency_store', function (): void {
    $accepted = new AcceptedDelivery('01JCDEF0000000000000000000', false);
    $run = fn (): array => $accepted->toStored();

    $first = app(SentinelManager::class)->idempotency()->run('round-trip-key', 'webhooks:test', $run, 'digest', 60);
    $second = app(SentinelManager::class)->idempotency()->run('round-trip-key', 'webhooks:test', $run, 'digest', 60);

    expect(AcceptedDelivery::fromStored($first->value, $first->replayed))->toEqual($accepted)
        ->and(AcceptedDelivery::fromStored($second->value, $second->replayed))->toEqual(new AcceptedDelivery($accepted->id, true))
        ->and(AcceptedDelivery::fromStored('garbage'))->toEqual(new AcceptedDelivery('', false))
        ->and(AcceptedDelivery::fromStored(['id' => 7, 'duplicate' => 'yes']))->toEqual(new AcceptedDelivery('', false));
});

it('answers success when the idempotency store fails after the delivery was stored', function (bool $unsigned, int $failOn): void {
    app()->instance(IdempotencyStore::class, new FlakyIdempotencyStore(app(IdempotencyStore::class), $failOn));
    $endpoint = inboundEndpoint();

    if ($unsigned) {
        unsignedScheme();
        $endpoint->forceFill(['signature_scheme' => 'unsigned'])->save();
    }

    $send = fn () => SignedWebhookRequest::for($endpoint)->id('msg_kept')->withHeader('X-Delivery', 'msg_kept')
        ->signUsing(fn (OutgoingWebhook $message, EndpointSecrets $secrets) => (new DefaultScheme)->sign($message, $secrets))->send();

    // Stored and queued, but its result could not be kept: still the sender's success…
    $send()->assertStatus(202)->assertJson(['status' => 'accepted', 'id' => InboundDelivery::query()->sole()->public_id]);
    // …and its retry a duplicate, not a 409 for the key's whole TTL.
    $send()->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
})->with([
    'signed id' => [false, 1],
    'unsigned id, the id run' => [true, 1],
    'unsigned id, the body guard' => [true, 2],
]);

it('keeps refusing a retry whose unkept delivery is gone', function (): void {
    app()->instance(IdempotencyStore::class, new FlakyIdempotencyStore(app(IdempotencyStore::class)));
    $endpoint = inboundEndpoint();

    SignedWebhookRequest::for($endpoint)->id('msg_gone')->send()->assertStatus(202);
    InboundDelivery::query()->forceDelete();

    // The key is completed as unreplayable and no delivery backs it: sentinel's 409 stands.
    SignedWebhookRequest::for($endpoint)->id('msg_gone')->send()->assertStatus(409);
});

it('lets a store failure before anything was stored surface', function (): void {
    app()->instance(IdempotencyStore::class, new FlakyIdempotencyStore(app(IdempotencyStore::class), failOn: 0));
    $this->withoutExceptionHandling();

    expect(fn () => SignedWebhookRequest::for(inboundEndpoint())->send())->toThrow(RuntimeException::class, 'unavailable');
    expect(InboundDelivery::query()->count())->toBe(0);
});

it('answers a replay from a scheme that names no nonce as a refusal', function (): void {
    Webhooks::schemes()->extend('replaying', static fn () => new class implements SignatureScheme
    {
        public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
        {
            throw WebhookSignatureException::because(RejectionReason::Replayed);
        }
    });
    $endpoint = inboundEndpoint(['signature_scheme' => 'replaying']);

    rawPost($endpoint->url(), '{}')->assertStatus(401)->assertJson(['code' => 'replayed']);
    rawPost($endpoint->url(), '{}', ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE_INPUT' => 'sig1=();created=1'])->assertStatus(401);
});

it('queues a stalled delivery found by its key when the duplicate carries no public id', function (): void {
    Queue::fake();
    $endpoint = inboundEndpoint();
    SignedWebhookRequest::for($endpoint)->id('msg_stalled')->send()->assertStatus(202);
    $delivery = InboundDelivery::query()->sole();
    $delivery->forceFill(['status' => InboundStatus::Received, 'queued_at' => null])->save();

    app(AcceptInboundDeliveryAction::class)->requeueStalled($endpoint, 'msg_stalled');

    expect($delivery->refresh()->status)->toBe(InboundStatus::Queued);
});
