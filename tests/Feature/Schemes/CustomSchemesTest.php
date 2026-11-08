<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use RoundlyConsulting\Webhooks\Support\Secrets;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers\BillingWebhookHandler;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes\GitHubScheme;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes\StripeScheme;

beforeEach(function (): void {
    Queue::fake();
    config()->set('webhooks.schemes', [
        'default' => DefaultScheme::class,
        'rfc9421' => Rfc9421Scheme::class,
        'github' => GitHubScheme::class,
    ]);
    Webhooks::schemes()->extend('stripe', StripeScheme::class);
});

it('a_custom_github_style_scheme_verifies', function (): void {
    $secret = 'gh-shared-secret';   // a plain string, imported verbatim
    $endpoint = Webhooks::inbound()->create(scheme: 'github', secret: $secret)->endpoint;
    $body = '{"action":"opened"}';
    $post = fn (string $signature, string $delivery) => $this->call('POST', $endpoint->url(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_GITHUB_DELIVERY' => $delivery,
        'HTTP_X_GITHUB_EVENT' => 'pull_request',
    ], $body);

    $post('sha256='.hash_hmac('sha256', $body, $secret), 'd-1')->assertStatus(202);
    $post('sha256='.hash_hmac('sha256', $body, 'other'), 'd-2')->assertStatus(401);
    $post('sha256=zz', 'd-3')->assertStatus(401);

    $delivery = InboundDelivery::query()->sole();
    expect($delivery->event_type)->toBe('pull_request')->and($delivery->external_id)->toBe('d-1');
});

it('a_custom_stripe_style_scheme_verifies', function (): void {
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
    $secret = 'whsec_StripeStyleSecretUsedAsRawBytes';   // whsec_ — yet raw bytes, prefix included
    $endpoint = Webhooks::inbound()->create(scheme: 'stripe', secret: $secret)->endpoint;
    $body = '{"id":"evt_1","type":"invoice.paid"}';
    $t = (string) CarbonImmutable::now()->getTimestamp();

    $this->call('POST', $endpoint->url(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret),
    ], $body)->assertStatus(202);

    expect(InboundDelivery::query()->sole()->external_id)->toBe('evt_1');
    CarbonImmutable::setTestNow();
});

it('an_imported_secret_is_used_as_its_bytes', function (): void {
    expect(Secrets::keyBytes('plain-secret', SecretEncoding::StandardWebhooks))->toBe('plain-secret')
        ->and(Secrets::keyBytes('whsec_'.base64_encode('abc'), SecretEncoding::StandardWebhooks))->toBe('abc')
        ->and(Secrets::keyBytes('whsec_not base64!', SecretEncoding::StandardWebhooks))->toBeNull()
        ->and(Secrets::keyBytes('whsec_', SecretEncoding::StandardWebhooks))->toBeNull()
        ->and(Secrets::keyBytes('', SecretEncoding::Raw))->toBeNull();

    $endpoint = Webhooks::inbound()->create(secret: 'plain-secret')->endpoint;
    expect($endpoint->secret)->toBe('plain-secret');

    postSigned($endpoint)->assertStatus(202);
});

it('a_stripe_whsec_secret_is_used_raw_by_its_scheme', function (): void {
    expect(Secrets::keyBytes('whsec_abc', SecretEncoding::Raw))->toBe('whsec_abc');
});

it('refuses an imported secret it cannot store', function (string $secret): void {
    Webhooks::inbound()->create(secret: $secret);
})->with(['empty' => '', 'control' => "a\nb", 'too long' => str_repeat('s', 1025)])
    ->throws(InvalidEndpointException::class);

it('registering_a_non_scheme_class_throws', function (): void {
    expect(fn () => Webhooks::schemes()->extend('bad', stdClass::class))->toThrow(UnknownSchemeException::class)
        ->and(fn () => Webhooks::schemes()->extend('Bad Name', GitHubScheme::class))->toThrow(UnknownSchemeException::class)
        ->and(fn () => Webhooks::schemes()->resolve('missing'))->toThrow(UnknownSchemeException::class)
        ->and(fn () => Webhooks::handlers()->register('bad', stdClass::class))->toThrow(UnknownHandlerException::class)
        ->and(fn () => Webhooks::handlers()->register('Bad Alias', stdClass::class))->toThrow(UnknownHandlerException::class)
        ->and(fn () => Webhooks::inbound()->create(handler: 'missing'))->toThrow(UnknownHandlerException::class)
        ->and(fn () => Webhooks::inbound()->create(scheme: 'missing'))->toThrow(UnknownSchemeException::class);

    Webhooks::schemes()->extend('closure', static fn () => new stdClass);
    expect(fn () => Webhooks::schemes()->resolve('closure'))->toThrow(UnknownSchemeException::class);

    config()->set('webhooks.schemes', ['broken' => stdClass::class]);
    expect(fn () => Webhooks::schemes()->names())->toThrow(InvalidWebhooksConfigurationException::class, 'webhooks.schemes');
});

it('a_scheme_extended_in_boot_survives_a_scope_reset', function (): void {
    app()->register(new class(app()) extends ServiceProvider
    {
        public function boot(): void
        {
            Webhooks::schemes()->extend('github-boot', GitHubScheme::class);
            Webhooks::handlers()->register('ledger-boot', BillingWebhookHandler::class);
        }
    });

    // What a queue worker does before every job, and Octane between requests.
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    expect(Webhooks::schemes()->has('github-boot'))->toBeTrue()
        ->and(Webhooks::schemes()->resolve('github-boot'))->toBeInstanceOf(GitHubScheme::class)
        ->and(Webhooks::handlers()->has('ledger-boot'))->toBeTrue()
        ->and(Webhooks::schemes())->toBe(app(SchemeRegistry::class))
        ->and(Webhooks::handlers())->toBe(app(HandlerRegistry::class));
});

it('forget_removes_a_registration', function (): void {
    Webhooks::handlers()->register('ledger', BillingWebhookHandler::class);

    Webhooks::schemes()->forget('stripe');
    Webhooks::handlers()->forget('ledger');
    Webhooks::schemes()->forget('never-registered');

    expect(Webhooks::schemes()->has('stripe'))->toBeFalse()
        ->and(Webhooks::handlers()->has('ledger'))->toBeFalse()
        // A configured one is not a registration: forget() leaves it.
        ->and(Webhooks::schemes()->has('github'))->toBeTrue();

    Webhooks::schemes()->forget('github');
    expect(Webhooks::schemes()->has('github'))->toBeTrue();
});

it('stripe_recipe_marks_the_body_id_signed', function (): void {
    $secret = 'whsec_StripeStyleSecretUsedAsRawBytes';
    $secrets = new EndpointSecrets([$secret], app(MacEngine::class));
    $now = CarbonImmutable::now();
    $verify = function (string $body) use ($secret, $secrets, $now): VerifiedWebhook {
        $t = (string) $now->getTimestamp();
        $headers = ['stripe-signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret)];

        return (new StripeScheme)->verify(new IncomingWebhook($headers, $body, $now, '1', 300), $secrets);
    };

    $event = $verify('{"id":"evt_1","type":"invoice.paid"}');
    $scalar = $verify('"not an object"');

    expect($event->idIsSigned)->toBeTrue()->and($event->deliveryId)->toBe('evt_1')
        // A body that is no JSON object: no id, no crash (the pipeline refuses it with a 400 later).
        ->and($scalar->deliveryId)->toBeNull();
});

it('stripe_recipe_claims_no_signed_id_for_a_body_without_one', function (): void {
    $secret = 'whsec_StripeStyleSecretUsedAsRawBytes';
    $now = CarbonImmutable::now();
    $t = (string) $now->getTimestamp();
    $body = '{"type":"charge.succeeded"}';
    $headers = ['stripe-signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret)];

    $verified = (new StripeScheme)->verify(new IncomingWebhook($headers, $body, $now, '1', 300), new EndpointSecrets([$secret], app(MacEngine::class)));

    // No id in the body: whatever id the pipeline falls back to is not covered by the signature.
    expect($verified->deliveryId)->toBeNull()->and($verified->idIsSigned)->toBeFalse();
});

it('does_not_trust_a_signed_id_flag_without_an_id', function (): void {
    Webhooks::schemes()->extend('bodyonly', fn (): SignatureScheme => new class implements SignatureScheme
    {
        public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
        {
            $slot = $secrets->matches($request->body, (string) hex2bin((string) $request->header('x-sig')), SecretEncoding::Raw)
                ?? throw WebhookSignatureException::because(RejectionReason::InvalidSignature);

            // Claims a signed id but returns none.
            return new VerifiedWebhook(null, null, null, true, $slot);
        }
    });
    $endpoint = Webhooks::inbound()->create(scheme: 'bodyonly', secret: 'raw-shared-secret')->endpoint;
    $body = '{"type":"charge.succeeded","amount":5}';
    $post = fn (string $key) => $this->call('POST', $endpoint->url(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIG' => hash_hmac('sha256', $body, 'raw-shared-secret'),
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ], $body);

    $post('k1')->assertStatus(202);
    // The captured request replayed under a fresh, unsigned Idempotency-Key: the body guard holds.
    $post('attacker-fresh')->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
});

it('stripe_recipe_bounds_the_signature_entries_it_checks', function (): void {
    $secret = 'whsec_StripeStyleSecretUsedAsRawBytes';
    $now = CarbonImmutable::now();
    $t = (string) $now->getTimestamp();
    $body = '{"id":"evt_1"}';
    $valid = 'v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
    $verify = fn (string $header): VerifiedWebhook => (new StripeScheme)->verify(
        new IncomingWebhook(['stripe-signature' => $header], $body, $now, '1', 300),
        new EndpointSecrets([$secret], app(MacEngine::class)),
    );

    expect($verify("t={$t},".str_repeat('v1=00,', 7).$valid)->deliveryId)->toBe('evt_1')
        // Each entry costs a MAC per secret over the body: a caller cannot make it a hundred.
        ->and(fn () => $verify("t={$t},".str_repeat('v1=00,', 8).$valid))->toThrow(WebhookSignatureException::class)
        ->and(fn () => $verify("t={$t},v0=".str_repeat('a', 4096).','.$valid))->toThrow(WebhookSignatureException::class);
});

it('a_timestampless_scheme_refuses_a_captured_body_after_the_dedupe_ttl', function (): void {
    $secret = 'gh-shared-secret';
    $endpoint = Webhooks::inbound()->create(scheme: 'github', secret: $secret)->endpoint;
    $body = '{"action":"opened"}';
    $post = fn (string $delivery) => $this->call('POST', $endpoint->url(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'HTTP_X_GITHUB_DELIVERY' => $delivery,
        'HTTP_X_GITHUB_EVENT' => 'pull_request',
    ], $body);

    $post('d-1')->assertStatus(202);
    // No timestamp in the format: once the idempotency store forgot the body, the stored
    // delivery's digest still recognises the captured request under a fresh delivery id.
    $this->travel(config('webhooks.inbound.dedupe_ttl') + 60)->seconds();
    $post('d-2')->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(InboundDelivery::query()->count())->toBe(1);
});

it('a_same_body_duplicate_under_a_fresh_id_queues_the_stalled_original', function (): void {
    $secret = 'gh-shared-secret';
    $endpoint = Webhooks::inbound()->create(scheme: 'github', secret: $secret)->endpoint;
    $body = '{"action":"closed"}';
    $post = fn (string $delivery) => $this->call('POST', $endpoint->url(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'HTTP_X_GITHUB_DELIVERY' => $delivery,
    ], $body);

    $post('d-1')->assertStatus(202);
    $original = InboundDelivery::query()->sole();
    // As if the dispatch after the insert had failed.
    $original->forceFill(['status' => InboundStatus::Received, 'queued_at' => null])->save();

    $post('d-2')->assertStatus(200)->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(ProcessInboundDelivery::class, 2);
    expect($original->refresh()->status)->toBe(InboundStatus::Queued);
});
