<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Webhooks\Contracts\PayloadSanitizer;
use RoundlyConsulting\Webhooks\DataTransferObjects\SanitizeContext;
use RoundlyConsulting\Webhooks\Enums\Direction;
use RoundlyConsulting\Webhooks\Enums\DuplicateResponse;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;
use RoundlyConsulting\Webhooks\Support\KeyRedactingSanitizer;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

it('config_values_are_read_strictly', function (string $key, mixed $value, string $reader): void {
    config()->set($key, $value);

    expect(fn () => WebhooksConfig::{$reader}())->toThrow(InvalidConfigurationException::class, $key);
})->with([
    ['webhooks.inbound.tolerance', 0, 'tolerance'],
    ['webhooks.inbound.tolerance', 'five', 'tolerance'],
    ['webhooks.inbound.tolerance', 3601, 'tolerance'],
    ['webhooks.outbound.max_attempts', 26, 'maxAttempts'],
    ['webhooks.outbound.timeouts.total', 2, 'totalTimeout'],
    ['webhooks.outbound.disable_after', 0, 'disableAfter'],
    ['webhooks.outbound.allow_http', 'maybe', 'allowHttp'],
    ['webhooks.ssrf.enabled', 'disabled', 'ssrfEnabled'],
    ['webhooks.inbound.on_duplicate', 'ignore', 'onDuplicate'],
    ['webhooks.queue.max_delay', 0, 'maxDelay'],
    ['webhooks.secrets.bytes', 8, 'secretBytes'],
    ['webhooks.secrets.grace_seconds', 999999, 'graceSeconds'],
    ['webhooks.inbound.handler.backoff', [10, 'x'], 'handlerBackoff'],
    ['webhooks.inbound.handler.backoff', 5, 'handlerBackoff'],
    ['webhooks.outbound.retry.schedule', [0], 'retrySchedule'],
    ['webhooks.ssrf.allowed_ports', [70000], 'allowedPorts'],
    ['webhooks.inbound.content_types', ['text plain'], 'inboundContentTypes'],
    ['webhooks.outbound.event_types', ['Bad Type'], 'knownEventTypes'],
    ['webhooks.schedule.retry', 'hourly', 'retryFrequency'],
    ['webhooks.schedule.prune', 'everyMinute', 'pruneFrequency'],
    ['webhooks.tables.inbound_endpoints', 'bad name!', 'inboundEndpointsTable'],
    ['webhooks.schemes', ['Bad Name' => KeyRedactingSanitizer::class], 'schemes'],
    ['webhooks.handlers', [7 => KeyRedactingSanitizer::class], 'handlers'],
    ['webhooks.payloads.delivery_retention_days', 'forever', 'deliveryRetentionDays'],
    ['webhooks.queue.inbound', 5, 'inboundQueue'],
]);

it('reads a blank value as not set', function (): void {
    foreach (['webhooks.inbound.tolerance', 'webhooks.outbound.max_attempts', 'webhooks.ssrf.enabled', 'webhooks.queue.connection', 'webhooks.inbound.handler.backoff', 'webhooks.ssrf.allowed_ports', 'webhooks.outbound.retry.schedule', 'webhooks.sanitizer.class', 'webhooks.payloads.delivery_retention_days'] as $key) {
        config()->set($key, '');
    }

    expect(WebhooksConfig::tolerance())->toBe(300)
        ->and(WebhooksConfig::maxAttempts())->toBe(8)
        ->and(WebhooksConfig::ssrfEnabled())->toBeTrue()
        ->and(WebhooksConfig::queueConnection())->toBeNull()
        ->and(WebhooksConfig::handlerBackoff())->toBe([10, 60, 300])
        ->and(WebhooksConfig::allowedPorts())->toBeNull()
        ->and(WebhooksConfig::retrySchedule())->toBeNull()
        ->and(WebhooksConfig::sanitizer())->toBe(KeyRedactingSanitizer::class)
        ->and(WebhooksConfig::deliveryRetentionDays())->toBeNull()
        ->and(WebhooksConfig::onDuplicate())->toBe(DuplicateResponse::Accept);
});

it('reads env strings the way they are meant', function (): void {
    config()->set('webhooks.ssrf.allowed_ports', '443, 8443');
    config()->set('webhooks.queue.queue', 'hooks');
    config()->set('webhooks.queue.outbound', 'hooks-out');
    config()->set('webhooks.inbound.route.url_base', 'https://in.example.com/');
    config()->set('webhooks.inbound.route.prefix', '/hooks/');
    config()->set('webhooks.inbound.store_headers', 'Content-Type, Authorization, X-Signature, X-Trace');

    expect(WebhooksConfig::allowedPorts())->toBe([443, 8443])
        ->and(WebhooksConfig::inboundQueue())->toBe('hooks')
        ->and(WebhooksConfig::outboundQueue())->toBe('hooks-out')
        ->and(WebhooksConfig::inboundUrlBase())->toBe('https://in.example.com')
        ->and(WebhooksConfig::routePrefix())->toBe('hooks')
        ->and(WebhooksConfig::storedHeaders())->toBe(['content-type', 'x-trace']);
});

it('refuses a sanitizer that is not one', function (): void {
    config()->set('webhooks.sanitizer.class', stdClass::class);

    expect(fn () => WebhooksConfig::sanitizer())->toThrow(InvalidWebhooksConfigurationException::class, 'webhooks.sanitizer.class');

    config()->set('webhooks.sanitizer.class', KeyRedactingSanitizer::class);
    app()->bind(KeyRedactingSanitizer::class, fn () => new stdClass);
    expect(fn () => app(PayloadSanitizer::class))->toThrow(InvalidWebhooksConfigurationException::class);
});

it('caps a deep payload at the redaction depth', function (): void {
    $deep = ['a' => 1];

    for ($i = 0; $i < 70; $i++) {
        $deep = ['n' => $deep];
    }

    $sanitized = (new KeyRedactingSanitizer)->sanitize($deep, new SanitizeContext(
        Direction::Inbound, inboundEndpoint(), null,
    ));

    expect(json_encode($sanitized))->toContain('[redacted]');
});
