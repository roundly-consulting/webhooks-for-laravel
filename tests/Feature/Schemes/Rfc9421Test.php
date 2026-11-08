<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Sentinel\Enums\Algorithm;
use RoundlyConsulting\Sentinel\Facades\Sentinel;
use RoundlyConsulting\Sentinel\Keys\KeyStoreManager;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
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

    // Verified (nonce remembered), then refused at the decode step: nothing stored…
    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(400);
    // …so the resend is not a silent "200 duplicate".
    $this->call('POST', $signed->uri, [], [], [], $server, $signed->body)->assertStatus(401)->assertJson(['code' => 'replayed']);
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
