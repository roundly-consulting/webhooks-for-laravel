<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Support\Secrets;
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

it('registries_do_not_leak_between_requests', function (): void {
    Webhooks::schemes()->extend('temporary', GitHubScheme::class);
    expect(Webhooks::schemes()->has('temporary'))->toBeTrue();

    // A new request/job scope (Octane flushes scoped instances between requests).
    app()->forgetScopedInstances();

    expect(Webhooks::schemes()->has('temporary'))->toBeFalse()
        ->and(Webhooks::schemes()->has('github'))->toBeTrue();
});
