<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\Contracts\PayloadSanitizer;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\Enums\DuplicateResponse;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;
use RoundlyConsulting\Webhooks\Http\Resources\WebhookOwnerResource;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;

/**
 * Every `webhooks.*` setting, read strictly: a typo or an out-of-range value throws naming the
 * key, and a blank value means "not set" (the default). Nothing else in the package calls
 * `config('webhooks…')`.
 *
 * @internal
 */
final class WebhooksConfig
{
    public const int DEFAULT_PAYLOAD_BYTES = 262144;

    public static function keyType(): KeyType
    {
        return KeyType::fromConfig('webhooks.key_type');
    }

    public static function primaryKeyType(): KeyType
    {
        return KeyType::fromConfig('webhooks.primary_key_type');
    }

    public static function inboundEndpointsTable(): string
    {
        return self::tableName(Config::string('webhooks.tables.inbound_endpoints', 'webhook_inbound_endpoints'), 'webhooks.tables.inbound_endpoints');
    }

    public static function inboundDeliveriesTable(): string
    {
        return self::tableName(Config::string('webhooks.tables.inbound_deliveries', 'webhook_inbound_deliveries'), 'webhooks.tables.inbound_deliveries');
    }

    public static function outboundEndpointsTable(): string
    {
        return self::tableName(Config::string('webhooks.tables.outbound_endpoints', 'webhook_outbound_endpoints'), 'webhooks.tables.outbound_endpoints');
    }

    public static function outboundDeliveriesTable(): string
    {
        return self::tableName(Config::string('webhooks.tables.outbound_deliveries', 'webhook_outbound_deliveries'), 'webhooks.tables.outbound_deliveries');
    }

    public static function outboundAttemptsTable(): string
    {
        return self::tableName(Config::string('webhooks.tables.outbound_attempts', 'webhook_outbound_attempts'), 'webhooks.tables.outbound_attempts');
    }

    /**
     * The resource an endpoint's owner renders through (`webhooks.resources.owner`).
     *
     * @return class-string<JsonResource>
     *
     * @throws InvalidConfigurationException
     */
    public static function ownerResource(): string
    {
        $key = 'webhooks.resources.owner';
        $resource = config('webhooks.resources.owner');

        if ($resource === null || (is_string($resource) && trim($resource) === '')) {
            return WebhookOwnerResource::class;
        }

        if (! is_string($resource)
            || ! is_subclass_of($resource, JsonResource::class)
            || ! (new ReflectionClass($resource))->isInstantiable()) {
            throw InvalidConfigurationException::notAnImplementation($key, JsonResource::class, $resource);
        }

        return $resource;
    }

    public static function queueConnection(): ?string
    {
        return self::optional(Config::string('webhooks.queue.connection', ''));
    }

    public static function inboundQueue(): ?string
    {
        return self::optional(Config::string('webhooks.queue.inbound', '')) ?? self::defaultQueue();
    }

    public static function outboundQueue(): ?string
    {
        return self::optional(Config::string('webhooks.queue.outbound', '')) ?? self::defaultQueue();
    }

    public static function maxDelay(): int
    {
        return Config::integer('webhooks.queue.max_delay', 900, min: 1, max: 43200);
    }

    public static function routeEnabled(): bool
    {
        return Config::boolean('webhooks.inbound.route.enabled', true);
    }

    public static function routePrefix(): string
    {
        return trim(Config::string('webhooks.inbound.route.prefix', 'webhooks'), '/');
    }

    public static function routeDomain(): ?string
    {
        return self::optional(Config::string('webhooks.inbound.route.domain', ''));
    }

    /**
     * @return list<string>
     */
    public static function routeMiddleware(): array
    {
        return Config::list('webhooks.inbound.route.middleware', []);
    }

    public static function inboundUrlBase(): ?string
    {
        $base = self::optional(Config::string('webhooks.inbound.route.url_base', ''));

        return $base === null ? null : rtrim($base, '/');
    }

    public static function tolerance(): int
    {
        return Config::integer('webhooks.inbound.tolerance', 300, min: 1, max: 3600);
    }

    public static function inboundMaxPayloadBytes(): int
    {
        return Config::integer('webhooks.inbound.max_payload_bytes', self::DEFAULT_PAYLOAD_BYTES, min: 1, max: self::inboundPayloadCeiling());
    }

    public static function inboundPayloadCeiling(): int
    {
        return Config::integer('webhooks.inbound.max_payload_bytes_ceiling', 1048576, min: 1, max: 67108864);
    }

    /**
     * @return list<string>
     */
    public static function inboundContentTypes(): array
    {
        return array_map(strtolower(...), Config::list('webhooks.inbound.content_types', ['application/json', 'application/*+json'], self::mediaType(...)));
    }

    public static function onDuplicate(): DuplicateResponse
    {
        return Config::enum('webhooks.inbound.on_duplicate', DuplicateResponse::class, DuplicateResponse::Accept);
    }

    public static function acceptIdempotencyHeader(): bool
    {
        return Config::boolean('webhooks.inbound.accept_idempotency_header', true);
    }

    public static function dedupeTtl(): int
    {
        return Config::integer('webhooks.inbound.dedupe_ttl', 604800, min: 60, max: 2592000);
    }

    public static function inboundRateLimit(): int
    {
        return Config::integer('webhooks.inbound.rate_limit.per_minute', 600, min: 1);
    }

    public static function inboundRejectionLimit(): int
    {
        return Config::integer('webhooks.inbound.rate_limit.rejected_per_minute', 60, min: 1);
    }

    public static function rateLimitCacheStore(): ?string
    {
        return self::optional(Config::string('webhooks.inbound.rate_limit.cache_store', ''));
    }

    /**
     * @return list<string>
     */
    public static function storedHeaders(): array
    {
        $headers = array_map(strtolower(...), Config::list('webhooks.inbound.store_headers', ['content-type', 'user-agent', 'webhook-id', 'webhook-timestamp']));

        // Credentials and signatures are never stored, whatever the list says.
        return array_values(array_filter($headers, static fn (string $header): bool => ! HeaderRules::secretBearing($header)));
    }

    public static function inboundRequeueAfter(): int
    {
        return Config::integer('webhooks.inbound.requeue_after', 60, min: 1);
    }

    public static function handlerTries(): int
    {
        return Config::integer('webhooks.inbound.handler.tries', 3, min: 1, max: 100);
    }

    /**
     * @return list<int>
     */
    public static function handlerBackoff(): array
    {
        return self::integers('webhooks.inbound.handler.backoff', config('webhooks.inbound.handler.backoff'), [10, 60, 300], 0);
    }

    public static function handlerTimeout(): int
    {
        return Config::integer('webhooks.inbound.handler.timeout', 60, min: 1, max: 86400);
    }

    public static function allowHttp(): bool
    {
        return Config::boolean('webhooks.outbound.allow_http', false);
    }

    public static function verifyOnSave(): bool
    {
        return Config::boolean('webhooks.outbound.verify_on_save', true);
    }

    public static function envelope(): bool
    {
        return Config::boolean('webhooks.outbound.envelope', true);
    }

    /**
     * @return list<string>
     */
    public static function knownEventTypes(): array
    {
        return Config::list('webhooks.outbound.event_types', [], EventTypes::valid(...));
    }

    public static function outboundMaxPayloadBytes(): int
    {
        return Config::integer('webhooks.outbound.max_payload_bytes', self::DEFAULT_PAYLOAD_BYTES, min: 1, max: 67108864);
    }

    public static function maxResponseBytes(): int
    {
        return Config::integer('webhooks.outbound.max_response_bytes', 4096, min: 1, max: 1048576);
    }

    public static function connectTimeout(): int
    {
        return Config::integer('webhooks.outbound.timeouts.connect', 5, min: 1, max: 60);
    }

    public static function totalTimeout(): int
    {
        return Config::integer('webhooks.outbound.timeouts.total', 10, min: self::connectTimeout(), max: 300);
    }

    public static function maxAttempts(): int
    {
        return Config::integer('webhooks.outbound.max_attempts', 8, min: 1, max: 25);
    }

    public static function retryBase(): int
    {
        return Config::integer('webhooks.outbound.retry.base', 60, min: 1, max: 86400);
    }

    public static function retryMultiplier(): int
    {
        return Config::integer('webhooks.outbound.retry.multiplier', 4, min: 1, max: 100);
    }

    public static function retryCap(): int
    {
        return Config::integer('webhooks.outbound.retry.cap', 32400, min: 1, max: 604800);
    }

    public static function jitterPermille(): int
    {
        return Config::integer('webhooks.outbound.retry.jitter_permille', 100, min: 0, max: 500);
    }

    public static function maxRetryAfter(): int
    {
        return Config::integer('webhooks.outbound.retry.max_retry_after', 86400, min: 1, max: 604800);
    }

    /**
     * The explicit retry delays, or null for the formula.
     *
     * @return list<int>|null
     */
    public static function retrySchedule(): ?array
    {
        $schedule = self::integers('webhooks.outbound.retry.schedule', config('webhooks.outbound.retry.schedule'), [], 1);

        return $schedule === [] ? null : $schedule;
    }

    public static function outboundRateLimit(): int
    {
        return Config::integer('webhooks.outbound.rate_limit.per_minute', 60, min: 1);
    }

    public static function outboundRequeueAfter(): int
    {
        return Config::integer('webhooks.outbound.requeue_after', 120, min: 1);
    }

    public static function disableAfter(): int
    {
        return Config::integer('webhooks.outbound.disable_after', 5, min: 1);
    }

    public static function disableOnGone(): bool
    {
        return Config::boolean('webhooks.outbound.disable_on_gone', true);
    }

    public static function followRedirects(): bool
    {
        return Config::boolean('webhooks.outbound.follow_redirects', false);
    }

    public static function maxRedirects(): int
    {
        return Config::integer('webhooks.outbound.max_redirects', 3, min: 0, max: 10);
    }

    /**
     * @return list<string>
     */
    public static function allowedHeaderOverrides(): array
    {
        return array_map(strtolower(...), Config::list('webhooks.outbound.headers.allowed_overrides', []));
    }

    /**
     * The configured User-Agent, else "{app.name} Webhooks" (`Laravel Webhooks` without a name) —
     * the host's name, never ours.
     */
    public static function userAgent(): string
    {
        $configured = self::optional(Config::string('webhooks.outbound.user_agent', ''));

        if ($configured !== null) {
            return $configured;
        }

        $name = config('app.name');
        $name = is_string($name) ? trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name) ?? '') : '';

        return ($name === '' ? 'Laravel' : $name).' Webhooks';
    }

    public static function ssrfEnabled(): bool
    {
        return Config::boolean('webhooks.ssrf.enabled', true);
    }

    public static function blockAppHosts(): bool
    {
        return Config::boolean('webhooks.ssrf.block_app_hosts', true);
    }

    /**
     * @return list<string>
     */
    public static function appHosts(): array
    {
        return Config::list('webhooks.ssrf.app_hosts', []);
    }

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        return Config::list('webhooks.ssrf.allowed_hosts', []);
    }

    /**
     * @return list<string>
     */
    public static function blockedHosts(): array
    {
        return Config::list('webhooks.ssrf.blocked_hosts', []);
    }

    /**
     * @return list<string>
     */
    public static function allowedRanges(): array
    {
        return Config::list('webhooks.ssrf.allowed_ranges', []);
    }

    /**
     * @return list<string>
     */
    public static function blockedRanges(): array
    {
        return Config::list('webhooks.ssrf.blocked_ranges', []);
    }

    /**
     * @return list<int>|null
     */
    public static function allowedPorts(): ?array
    {
        $ports = self::integers('webhooks.ssrf.allowed_ports', config('webhooks.ssrf.allowed_ports'), [], 1);

        foreach ($ports as $port) {
            if ($port > 65535) {
                throw InvalidConfigurationException::notAValidListItem('webhooks.ssrf.allowed_ports', (string) $port);
            }
        }

        return $ports === [] ? null : $ports;
    }

    public static function secretBytes(): int
    {
        return Config::integer('webhooks.secrets.bytes', 32, min: 24, max: 64);
    }

    public static function graceSeconds(): int
    {
        return Config::integer('webhooks.secrets.grace_seconds', 86400, min: 0, max: self::maxGraceSeconds());
    }

    public static function maxGraceSeconds(): int
    {
        return Config::integer('webhooks.secrets.max_grace_seconds', 604800, min: 0, max: 31536000);
    }

    /**
     * The configured schemes by name (the two built-in ones unless the host removed them).
     *
     * @return array<string, class-string<SignatureScheme>>
     */
    public static function schemes(): array
    {
        $configured = config('webhooks.schemes');
        $configured = is_array($configured) ? $configured : ['default' => DefaultScheme::class, 'rfc9421' => Rfc9421Scheme::class];
        $schemes = [];

        foreach ($configured as $name => $class) {
            $schemes[self::name('webhooks.schemes', $name)] = self::implementation('webhooks.schemes', $class, SignatureScheme::class);
        }

        return $schemes;
    }

    public static function rfc9421Profile(): string
    {
        return Config::string('webhooks.rfc9421.profile', 'default');
    }

    public static function rfc9421OutboundKeyId(): ?string
    {
        return self::optional(Config::string('webhooks.rfc9421.outbound_key_id', ''));
    }

    /**
     * @return array<string, class-string<InboundHandler>>
     */
    public static function handlers(): array
    {
        $configured = config('webhooks.handlers');
        $configured = is_array($configured) ? $configured : [];
        $handlers = [];

        foreach ($configured as $alias => $class) {
            $handlers[self::name('webhooks.handlers', $alias)] = self::implementation('webhooks.handlers', $class, InboundHandler::class);
        }

        return $handlers;
    }

    /**
     * @return class-string<PayloadSanitizer>
     */
    public static function sanitizer(): string
    {
        $class = config('webhooks.sanitizer.class');

        if ($class === null || (is_string($class) && trim($class) === '')) {
            return KeyRedactingSanitizer::class;
        }

        return self::implementation('webhooks.sanitizer.class', $class, PayloadSanitizer::class);
    }

    /**
     * @return list<string>
     */
    public static function redactKeys(): array
    {
        return array_map(strtolower(...), Config::list('webhooks.sanitizer.redact_keys', ['password', 'secret', 'token', 'api_key', 'authorization', 'card_number', 'cvc', 'ssn']));
    }

    public static function redactReplacement(): string
    {
        return Config::string('webhooks.sanitizer.replacement', '[redacted]');
    }

    public static function encryptPayloads(): bool
    {
        return Config::boolean('webhooks.payloads.encrypt', true);
    }

    public static function retentionDays(): int
    {
        return Config::integer('webhooks.payloads.retention_days', 30, min: 1, max: 36500);
    }

    public static function deliveryRetentionDays(): ?int
    {
        $value = config('webhooks.payloads.delivery_retention_days');

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return Config::integer('webhooks.payloads.delivery_retention_days', 90, min: 1, max: 36500);
    }

    public static function scheduleEnabled(): bool
    {
        return Config::boolean('webhooks.schedule.enabled', true);
    }

    public static function retryFrequency(): string
    {
        return Config::oneOf('webhooks.schedule.retry', ['everyMinute', 'everyTwoMinutes', 'everyFiveMinutes'], 'everyMinute');
    }

    public static function pruneFrequency(): string
    {
        return Config::oneOf('webhooks.schedule.prune', ['hourly', 'daily', 'weekly'], 'daily');
    }

    private static function defaultQueue(): ?string
    {
        return self::optional(Config::string('webhooks.queue.queue', ''));
    }

    private static function optional(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A list of integers: an array of ints / integer strings, or an env comma list. Blank = default.
     *
     * @param  list<int>  $default
     * @return list<int>
     */
    private static function integers(string $key, mixed $value, array $default, int $min): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        $items = is_string($value) ? explode(',', $value) : $value;

        if (! is_array($items)) {
            throw InvalidConfigurationException::notAList($key, $value);
        }

        $integers = [];

        foreach ($items as $item) {
            $item = is_string($item) ? trim($item) : $item;

            if (is_string($item) && preg_match('/^-?[0-9]{1,9}$/', $item) === 1) {
                $item = (int) $item;
            }

            if (! is_int($item) || $item < $min) {
                throw InvalidConfigurationException::notAValidListItem($key, is_scalar($item) ? (string) $item : get_debug_type($item));
            }

            $integers[] = $item;
        }

        return $integers;
    }

    private static function tableName(string $value, string $key): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]{0,63}$/', $value) !== 1) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    private static function name(string $key, mixed $name): string
    {
        if (! is_string($name) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/', $name) !== 1) {
            throw InvalidConfigurationException::notAString($key, $name);
        }

        return $name;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return class-string<T>
     */
    private static function implementation(string $key, mixed $class, string $contract): string
    {
        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, $contract) || ! (new ReflectionClass($class))->isInstantiable()) {
            throw InvalidWebhooksConfigurationException::notAnImplementation($key, $contract);
        }

        return $class;
    }

    private static function mediaType(string $value): bool
    {
        return preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+*-]+$#i', trim($value)) === 1;
    }
}
