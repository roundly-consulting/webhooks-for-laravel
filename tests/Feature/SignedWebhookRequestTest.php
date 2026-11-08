<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutgoingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\SignatureHeaders;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes\GitHubScheme;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes\StripeScheme;

/**
 * `SignedWebhookRequest` signs with the endpoint's own scheme — a host testing its GitHub- or
 * Stripe-style endpoint gets a request its scheme accepts — and builds the refused variants.
 */
beforeEach(function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
    Webhooks::schemes()->extend('github', GitHubScheme::class);
    Webhooks::schemes()->extend('stripe', StripeScheme::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A scheme that verifies `X-Sig: <hex HMAC(body)>` and cannot sign.
 */
final readonly class VerifyOnlyScheme implements SignatureScheme
{
    public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
    {
        $mac = hex2bin((string) $request->header('x-sig')) ?: '';
        $slot = $secrets->matches($request->body, $mac, SecretEncoding::Raw) ?? throw WebhookSignatureException::because(RejectionReason::InvalidSignature);

        return new VerifiedWebhook($request->header('x-id'), null, null, false, $slot);
    }
}

it('signs_a_custom_scheme_endpoint_with_the_schemes_own_signer', function (): void {
    $github = Webhooks::inbound()->create(scheme: 'github', secret: 'gh-shared-secret')->endpoint;
    $stripe = Webhooks::inbound()->create(scheme: 'stripe', secret: 'whsec_StripeStyleSecretUsedAsRawBytes')->endpoint;

    SignedWebhookRequest::for($github)->payload(['type' => 'pull_request', 'action' => 'opened'])->send()->assertStatus(202);
    SignedWebhookRequest::for($stripe)->payload(['id' => 'evt_1', 'type' => 'invoice.paid'])->send()->assertStatus(202);
    SignedWebhookRequest::for($github)->tamper()->send()->assertStatus(401);
    SignedWebhookRequest::for($stripe)->tamper()->send()->assertStatus(401);

    $deliveries = InboundDelivery::query()->orderBy('id')->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]->event_type)->toBe('pull_request')
        ->and($deliveries[1]->external_id)->toBe('evt_1');
});

it('sign_using_closure_is_used', function (): void {
    Webhooks::schemes()->extend('verify-only', VerifyOnlyScheme::class);
    $endpoint = Webhooks::inbound()->create(scheme: 'verify-only', secret: 'plain-secret')->endpoint;
    $seen = null;

    SignedWebhookRequest::for($endpoint)
        ->signUsing(function (OutgoingWebhook $message, EndpointSecrets $secrets) use (&$seen): SignatureHeaders {
            $seen = $message;

            return new SignatureHeaders(['X-Sig' => bin2hex($secrets->sign($message->body, SecretEncoding::Raw)[0]), 'X-Id' => $message->id]);
        })
        ->id('custom-1')
        ->send()
        ->assertStatus(202);

    expect($seen)->toBeInstanceOf(OutgoingWebhook::class)
        ->and($seen?->id)->toBe('custom-1')
        ->and($seen?->eventType)->toBe('test.event')
        ->and(InboundDelivery::query()->sole()->external_id)->toBe('custom-1');
});

it('a_custom_scheme_without_a_signer_throws_a_clear_error', function (): void {
    Webhooks::schemes()->extend('verify-only', VerifyOnlyScheme::class);
    $endpoint = Webhooks::inbound()->create(scheme: 'verify-only', secret: 'plain-secret')->endpoint;

    expect(fn () => SignedWebhookRequest::for($endpoint)->toRequest())->toThrow(LogicException::class, '[verify-only] scheme cannot sign');
});

it('unsigned_is_refused_with_missing_signature', function (): void {
    $endpoint = inboundEndpoint();
    $github = Webhooks::inbound()->create(scheme: 'github', secret: 'gh-shared-secret')->endpoint;

    SignedWebhookRequest::for($endpoint)->unsigned()->send()->assertStatus(401)->assertJson(['code' => 'missing_signature']);
    SignedWebhookRequest::for($github)->unsigned()->send()->assertStatus(401)->assertJson(['code' => 'missing_signature']);
    SignedWebhookRequest::for($endpoint)->withoutHeader('Webhook-Timestamp')->send()->assertStatus(401)->assertJson(['code' => 'missing_timestamp']);

    $data = SignedWebhookRequest::for($endpoint)->idempotencyKey('order-7')->withoutHeader('content-type')->toRequest();
    expect($data->headers)->toHaveKey('Idempotency-Key', 'order-7')
        ->and($data->headers)->not->toHaveKey('Content-Type');
});

it('signed_with_previous_without_a_previous_secret_throws', function (): void {
    expect(fn () => SignedWebhookRequest::for(inboundEndpoint())->signedWithPrevious()->toRequest())->toThrow(LogicException::class, 'no previous secret')
        ->and(fn () => SignedWebhookRequest::for(inboundEndpoint(['secret' => null]))->toRequest())->toThrow(LogicException::class, 'no secret');
});

it('rfc9421_without_a_key_id_throws', function (): void {
    $endpoint = inboundEndpoint(['signature_scheme' => 'rfc9421']);

    expect(fn () => SignedWebhookRequest::for($endpoint)->toRequest())->toThrow(LogicException::class, 'keyId()');
});

it('server_array_works_with_the_test_case_call', function (): void {
    $endpoint = inboundEndpoint();
    $data = SignedWebhookRequest::for($endpoint)->withHeader('X-Extra', 'yes')->toRequest();

    expect($data->server())->toMatchArray(['CONTENT_TYPE' => 'application/json', 'HTTP_X_EXTRA' => 'yes'])
        ->and($data->server())->toHaveKey('HTTP_WEBHOOK_SIGNATURE');

    $this->call('POST', $data->uri, [], [], [], $data->server(), $data->body)->assertStatus(202);
});
