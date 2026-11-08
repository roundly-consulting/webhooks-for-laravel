<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RoundlyConsulting\Sentinel\DataTransferObjects\DeferredSignature;
use RoundlyConsulting\Sentinel\DataTransferObjects\SigningOptions;
use RoundlyConsulting\Sentinel\DataTransferObjects\VerifiedSignature;
use RoundlyConsulting\Sentinel\Enums\Algorithm;
use RoundlyConsulting\Sentinel\Enums\Algorithm as SentinelAlgorithm;
use RoundlyConsulting\Sentinel\Facades\Sentinel;
use RoundlyConsulting\Sentinel\Http\Signatures\SignatureVerifier;
use RoundlyConsulting\Sentinel\Keys\KeyStoreManager;
use RoundlyConsulting\Webhooks\Contracts\PayloadSanitizer;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\SanitizeContext;
use RoundlyConsulting\Webhooks\DataTransferObjects\SignedRequestData;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Events\EndpointDisabled;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;

const PARTNER_KEY = 'base64:cGFydG5lci1zaGFyZWQtc2VjcmV0LTMyLWJ5dGVzLWxvbmctZm9yLWhtYWM=';

/**
 * The partner signs with its key (ring `partner`, the outbound ring here); this app knows the
 * same key verify-only in ring `http`, bound to the endpoint it belongs to.
 */
function rfc9421Endpoint(?InboundEndpoint $owner = null, array $options = ['id_header' => 'webhook-id']): InboundEndpoint
{
    config()->set('sentinel.keys.rings.partner', ['driver' => 'config', 'key_id' => 'partner', 'algorithm' => 'hmac-sha256', 'key' => PARTNER_KEY, 'algorithms' => ['hmac-sha256']]);
    config()->set('sentinel.signatures.outbound.ring', 'partner');
    config()->set('sentinel.keys.rings.http.driver', 'database');
    app(KeyStoreManager::class)->flush();

    $endpoint = Webhooks::inbound()->create(scheme: 'rfc9421', options: new InboundEndpointOptions(schemeOptions: $options))->endpoint;
    Sentinel::keys()->ring('http')->import('partner', Algorithm::HmacSha256, PARTNER_KEY, owner: $owner ?? $endpoint);

    return $endpoint;
}

beforeEach(fn () => Queue::fake());

it('an_rfc9421_delivery_verifies_through_sentinel', function (): void {
    $endpoint = rfc9421Endpoint();

    SignedWebhookRequest::for($endpoint)->keyId('partner')->send()->assertStatus(202);

    // The webhook-id header is a covered component here: the id is signed.
    expect(InboundDelivery::query()->sole()->idempotency_key)->toStartWith('msg_');
});

it('the_signed_request_builder_is_accepted', function (): void {
    postSigned(inboundEndpoint())->assertStatus(202);
    SignedWebhookRequest::for(rfc9421Endpoint())->keyId('partner')->send()->assertStatus(202);
});

it('tampered_and_stale_builders_are_rejected', function (): void {
    SignedWebhookRequest::for(inboundEndpoint())->tamper()->send()->assertStatus(401);
    SignedWebhookRequest::for(inboundEndpoint())->staleBy(3600)->send()->assertStatus(401);
    SignedWebhookRequest::for(rfc9421Endpoint())->keyId('partner')->tamper()->send()->assertStatus(401);
});

it('an_rfc9421_key_of_another_endpoint_is_refused', function (): void {
    $other = InboundEndpoint::factory()->create();
    $endpoint = rfc9421Endpoint($other);

    SignedWebhookRequest::for($endpoint)->keyId('partner')->send()->assertStatus(401)->assertJson(['code' => 'key_not_bound']);
});

it('an_rfc9421_replay_answers_as_a_duplicate', function (): void {
    $endpoint = rfc9421Endpoint();
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest();
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($signed->headers as $name => $value) {
        $server[strtoupper(str_replace('-', '_', $name)) === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(202);
    // The byte-identical resend: sentinel's nonce store says "replayed", and the delivery is stored.
    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
});

it('an_rfc9421_replay_of_an_unstored_request_is_refused', function (): void {
    $endpoint = rfc9421Endpoint();
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->body('not json')->toRequest();
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($signed->headers as $name => $value) {
        $server[strtoupper(str_replace('-', '_', $name)) === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    // Verified, then refused at the decode step: nothing stored, and the nonce never committed…
    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(400);
    // …so the resend is judged again on its own merits — never a silent "200 duplicate".
    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(400)->assertJson(['code' => 'invalid_payload']);
});

it('falls back to the signed nonce when there is no id header', function (): void {
    $endpoint = rfc9421Endpoint(options: ['id_header' => 'x-none']);

    SignedWebhookRequest::for($endpoint)->keyId('partner')->send()->assertStatus(202);

    expect(InboundDelivery::query()->sole()->idempotency_key)->toStartWith('nonce:');
});

it('refuses a missing RFC 9421 signature', function (): void {
    $endpoint = rfc9421Endpoint();

    $this->call('POST', $endpoint->url(), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401)->assertJson(['code' => 'missing_signature']);
});

it('signs outbound RFC 9421 deliveries with a sentinel key', function (): void {
    rfc9421Endpoint();
    publicDns();
    Http::fake(['*' => Http::response('ok')]);
    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in', scheme: 'rfc9421', options: new OutboundEndpointOptions(schemeOptions: ['key_id' => 'partner']))->endpoint;

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 1]));

    expect($delivery->status)->toBe(OutboundStatus::Succeeded);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Signature') && $request->hasHeader('Signature-Input')
        && str_contains($request->header('Signature-Input')[0], '"webhook-id"') && $request->hasHeader('webhook-id'));
});

it('fails an RFC 9421 delivery with no key to sign with', function (): void {
    publicDns();
    Http::fake();
    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in', scheme: 'rfc9421')->endpoint;

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 1]));

    Http::assertNothingSent();
    expect($delivery->status)->toBe(OutboundStatus::Failed)->and($delivery->last_error_code)->toBe(AttemptError::Signing);
});

it('never blames the receiver for a delivery this side cannot sign', function (): void {
    Event::fake([EndpointDisabled::class]);
    config()->set('webhooks.outbound.disable_after', 3);
    config()->set('webhooks.outbound.max_attempts', 1);
    publicDns();
    Http::fake();
    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in', scheme: 'rfc9421')->endpoint;
    $waiting = Webhooks::send($endpoint, 'invoice.paid', ['n' => 'waiting']);

    foreach (range(1, 5) as $i) {
        expect(attempt(Webhooks::send($endpoint->refresh(), 'invoice.paid', ['n' => $i]))->last_error_code)->toBe(AttemptError::Signing);
    }

    Http::assertNothingSent();
    Event::assertNotDispatched(EndpointDisabled::class);
    expect($endpoint->refresh()->enabled)->toBeTrue()
        ->and($endpoint->consecutive_failures)->toBe(0)
        ->and($waiting->refresh()->status)->toBe(OutboundStatus::Pending);
});

it('refuses an outbound scheme that cannot sign', function (): void {
    Webhooks::schemes()->extend('verify-only', static fn () => new class implements SignatureScheme
    {
        public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
        {
            throw WebhookSignatureException::because(RejectionReason::InvalidSignature);
        }
    });
    publicDns();
    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in')->endpoint;

    expect(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', scheme: 'verify-only'))->toThrow(InvalidEndpointException::class, 'cannot sign')
        ->and(fn () => Webhooks::outbound()->update($endpoint, new OutboundEndpointChanges(scheme: 'verify-only')))->toThrow(InvalidEndpointException::class, 'cannot sign')
        ->and($endpoint->refresh()->signature_scheme)->toBe('default')
        // RFC 9421 signs with a sentinel key, which may come later: accepted, and the doctor warns.
        ->and(Webhooks::outbound()->create('https://hooks.example.com/in', scheme: 'rfc9421')->endpoint->signature_scheme)->toBe('rfc9421');

    $this->artisan('webhooks:doctor')->expectsOutputToContain(__('webhooks::commands.doctor.rfc9421_outbound_no_key'));
    config()->set('webhooks.rfc9421.outbound_key_id', 'partner');
    $this->artisan('webhooks:doctor')->doesntExpectOutputToContain(__('webhooks::commands.doctor.rfc9421_outbound_no_key'));
});

it('builds requests by the scheme class, not its name', function (): void {
    config()->set('webhooks.schemes.sig', Rfc9421Scheme::class);
    config()->set('webhooks.schemes.std', DefaultScheme::class);
    rfc9421Endpoint();   // the partner ring and key store
    $sig = Webhooks::inbound()->create(scheme: 'sig')->endpoint;
    Sentinel::keys()->ring('http')->import('partner-sig', Algorithm::HmacSha256, PARTNER_KEY, owner: $sig);
    config()->set('sentinel.keys.rings.partner.key_id', 'partner-sig');
    app(KeyStoreManager::class)->flush();
    $std = Webhooks::inbound()->create(scheme: 'std', secret: V2_SECRET)->endpoint;

    SignedWebhookRequest::for($sig)->keyId('partner-sig')->send()->assertStatus(202);
    SignedWebhookRequest::for($sig)->keyId('partner-sig')->tamper()->send()->assertStatus(401);
    expect(SignedWebhookRequest::for($std)->unsigned()->toRequest()->headers)->toHaveKeys([DefaultScheme::ID, DefaultScheme::TIMESTAMP]);
    SignedWebhookRequest::for($std)->unsigned()->send()->assertStatus(401)->assertJson(['code' => 'missing_signature']);
});

it('doctor finds the key bound to an RFC 9421 endpoint', function (): void {
    config()->set('queue.default', 'database');
    config()->set('http-client-rate-limits.store', 'cache');
    config()->set('cache.stores.shared', ['driver' => 'redis']);
    config()->set('webhooks.inbound.rate_limit.cache_store', 'shared');
    config()->set('sentinel.signatures.profiles.default.components', ['@method', '@authority', '@path', 'webhook-id']);
    rfc9421Endpoint();

    $this->artisan('webhooks:doctor')->expectsOutputToContain('No problems found')->assertSuccessful();
});

it('signs the query of an outbound RFC 9421 delivery so a default-profile receiver accepts it', function (): void {
    rfc9421Endpoint();
    publicDns();
    Http::fake(['*' => Http::response('ok')]);
    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in?token=abc', scheme: 'rfc9421', options: new OutboundEndpointOptions(schemeOptions: ['key_id' => 'partner']))->endpoint;

    attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 1]));

    $sent = Http::recorded()[0][0];
    $received = Request::create($sent->url(), 'POST', [], [], [], [], $sent->body());

    foreach ($sent->headers() as $name => $values) {
        $received->headers->set($name, implode(', ', $values));
    }

    // The receiver's default profile requires `@query` whenever the target has one.
    $verified = Sentinel::signatures()->verify($received, 'default');

    expect($verified->components)->toContain('@query')
        ->and($sent->header('Signature-Input')[0])->toContain('"@query"');
});

it('answers a resend of a stored nonce-keyed RFC 9421 delivery as a duplicate', function (): void {
    $endpoint = rfc9421Endpoint(options: ['id_header' => 'x-none']);
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest();
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($signed->headers as $name => $value) {
        $server[strtoupper(str_replace('-', '_', $name)) === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(202);
    // No id header and no Idempotency-Key: the delivery is stored under its nonce, so the
    // sender's resend after a lost 202 is a duplicate, not a 401.
    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->sole()->idempotency_key)->toStartWith('nonce:');
});

it('accepts the unchanged resend of an RFC 9421 delivery it refused with 429', function (): void {
    $endpoint = rfc9421Endpoint();
    $endpoint->forceFill(['rate_limit_per_minute' => 1])->save();
    SignedWebhookRequest::for($endpoint)->keyId('partner')->send()->assertStatus(202);

    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest();
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($signed->headers as $name => $value) {
        $server[strtoupper(str_replace('-', '_', $name)) === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $refused = $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(429);
    $this->travel((int) $refused->headers->get('Retry-After') + 1)->seconds();

    // The 429 asked the sender to retry: the same bytes, nonce included, must still be accepted.
    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(202);

    expect(InboundDelivery::query()->count())->toBe(2);
});

it('keeps two RFC 9421 deliveries with the same body under an unsigned id apart', function (): void {
    $endpoint = rfc9421Endpoint(options: ['id_header' => 'x-delivery']);
    $send = fn (string $id) => SignedWebhookRequest::for($endpoint)->keyId('partner')->body('{"type":"ping"}')->withHeader('X-Delivery', $id)->toRequest();
    $post = function ($signed) {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($signed->headers as $name => $value) {
            $server[strtoupper(str_replace('-', '_', $name)) === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $signed->uri, [], [], [], $server, $signed->body);
    };
    $first = $send('d-1');

    $post($first)->assertStatus(202);
    // Another delivery, byte-identical body, its own nonce: the nonce already rules out a
    // replay, so no body guard may merge it into the first.
    $post($send('d-2'))->assertStatus(202);
    // The verbatim resend of the first is still caught (its nonce, its stored id).
    $post($first)->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->pluck('idempotency_key')->sort()->values()->all())->toBe(['d-1', 'd-2']);
});

/**
 * POST a built RFC 9421 request byte for byte, as a sender's retry layer would resend it.
 */
function resendRfc9421(SignedRequestData $signed): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($signed->headers as $name => $value) {
        $server[strtoupper(str_replace('-', '_', $name)) === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', $signed->uri, [], [], [], $server, $signed->body);
}

it('accepts the unchanged resend of an RFC 9421 delivery refused with 400 after verification', function (): void {
    $endpoint = rfc9421Endpoint(options: ['id_header' => 'x-none']);
    config()->set('webhooks.inbound.accept_idempotency_header', false);
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->idempotencyKey('order-7')->toRequest();

    // Verified, then no usable id (the host does not take Idempotency-Key yet): 400.
    resendRfc9421($signed)->assertStatus(400)->assertJson(['code' => 'missing_id']);

    // Fixed on this side: the sender's identical retry, nonce and all, is accepted.
    config()->set('webhooks.inbound.accept_idempotency_header', true);
    resendRfc9421($signed)->assertStatus(202);

    expect(InboundDelivery::query()->sole()->idempotency_key)->toBe('order-7');
});

it('accepts the unchanged resend of an RFC 9421 delivery after a sanitizer 500', function (): void {
    $endpoint = rfc9421Endpoint();
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest();
    app()->bind(PayloadSanitizer::class, fn () => new class implements PayloadSanitizer
    {
        public static bool $down = true;

        public function sanitize(array $payload, SanitizeContext $context): array
        {
            return self::$down ? throw new RuntimeException('sanitizer down') : $payload;
        }
    });

    resendRfc9421($signed)->assertStatus(500)->assertJson(['code' => 'sanitizer_failed']);
    app(PayloadSanitizer::class)::$down = false;

    resendRfc9421($signed)->assertStatus(202);
    expect(InboundDelivery::query()->count())->toBe(1);
});

it('accepts the unchanged resend of an RFC 9421 delivery whose row could not be stored', function (): void {
    $endpoint = rfc9421Endpoint();
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest();
    $down = true;
    InboundDelivery::creating(function () use (&$down): void {
        if ($down) {
            $down = false;

            throw new RuntimeException('database gone');
        }
    });

    resendRfc9421($signed)->assertStatus(500);
    expect(InboundDelivery::query()->count())->toBe(0);

    resendRfc9421($signed)->assertStatus(202);
    expect(InboundDelivery::query()->count())->toBe(1);
});

it('commits the nonce of a stored delivery only, through sentinel', function (): void {
    $endpoint = rfc9421Endpoint();
    $fake = Sentinel::fake()->fakeVerifiedSignature(new VerifiedSignature(
        'sig1', 'http', 'partner', SentinelAlgorithm::HmacSha256, time(), null, 'nonce-once', null,
        ['@method', '@authority', '@path', 'content-digest', 'webhook-id'], $endpoint->getMorphClass(), $endpoint->getKey(),
    ));
    $broken = SignedWebhookRequest::for($endpoint)->keyId('partner')->body('not json')->toRequest();

    resendRfc9421($broken)->assertStatus(400);
    $fake->assertSignatureVerifiedDeferred('default');
    $fake->assertNonceNotCommitted('partner');

    resendRfc9421(SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest())->assertStatus(202);
    $fake->assertNonceCommitted('partner');
    // Once committed, the same nonce is a replay — answered as a duplicate only if stored (it is).
    resendRfc9421(SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest())->assertStatus(401)->assertJson(['code' => 'replayed']);
});

it('verify() through the facade records the nonce itself', function (): void {
    $endpoint = rfc9421Endpoint();
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->toRequest();
    $request = Request::create($signed->uri, 'POST', [], [], [], [], $signed->body);

    foreach ($signed->headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    // A host running its own flow gets a used-up nonce, as before: no deferred commit to forget.
    expect(Webhooks::inbound()->verify($endpoint, $request)->replayProtected)->toBeTrue()
        ->and(fn () => Webhooks::inbound()->verify($endpoint, $request))->toThrow(WebhookSignatureException::class);
});

it('a copy that loses the nonce commit stores nothing and is answered as a replay', function (): void {
    $endpoint = rfc9421Endpoint(options: ['id_header' => 'x-delivery']);
    $signature = new VerifiedSignature(
        'sig1', 'http', 'partner', SentinelAlgorithm::HmacSha256, time(), null, 'nonce-raced', null,
        ['@method', '@authority', '@path', 'content-digest'], $endpoint->getMorphClass(), $endpoint->getKey(),
    );
    $fake = Sentinel::fake()->fakeVerifiedSignature($signature);
    $won = false;

    // Between this copy's verification and its commit, another copy (another worker) commits
    // the same nonce first.
    InboundDelivery::creating(function () use ($signature, &$won): void {
        if (! $won) {
            $won = true;
            Sentinel::commitSignatureNonce(new DeferredSignature($signature, SignatureVerifier::nonceScope('http', 'partner'), time() + 600, 'POST', '/'));
        }
    });

    resendRfc9421(SignedWebhookRequest::for($endpoint)->keyId('partner')->withHeader('X-Delivery', 'd-2')->toRequest())
        ->assertStatus(401)->assertJson(['code' => 'replayed']);

    expect(InboundDelivery::query()->count())->toBe(0);
    $fake->assertNonceCommitted('partner');
});

it('keeps the full body guard for an rfc9421 delivery without a nonce', function (): void {
    config()->set('sentinel.signatures.profiles.default.require_nonce', false);
    config()->set('sentinel.signatures.profiles.default.max_age', 3600);
    $endpoint = rfc9421Endpoint(options: ['id_header' => 'x-none']);
    $body = '{"type":"order.paid"}';
    $signed = Sentinel::signatures()->sign(
        new PsrRequest('POST', $endpoint->url(), ['Content-Type' => 'application/json'], $body),
        'partner',
        new SigningOptions(components: ['@method', '@authority', '@path', 'content-digest', 'content-type'], nonce: false),
    );
    $post = function (string $key) use ($signed, $body) {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key];

        foreach (['Signature', 'Signature-Input', 'Content-Digest'] as $name) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $signed->getHeaderLine($name);
        }

        return $this->call('POST', (string) $signed->getUri(), [], [], [], $server, $body);
    };

    $post('k-1')->assertStatus(202);
    // Past twice the tolerance, yet inside the profile's max_age: the captured request still
    // verifies, so the body guard must still hold.
    $this->travel(700)->seconds();
    $post('k-2')->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
});
