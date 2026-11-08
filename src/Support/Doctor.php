<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use Throwable;

/**
 * What `webhooks:doctor` checks: settings that silently weaken delivery or safety, stores that
 * do not hold across processes, and a sweep that is not running.
 *
 * @internal
 */
final readonly class Doctor
{
    public function __construct(
        private SchemeRegistry $schemes,
        private HandlerRegistry $handlers,
        private SentinelManager $sentinel,
    ) {}

    /** Minutes a due row may wait for the sweep before the doctor calls it stalled. */
    private const int STALL_MINUTES = 10;

    /** SQS' DelaySeconds limit. */
    private const int SQS_MAX_DELAY = 900;

    /**
     * @return list<DoctorFinding>
     */
    public function findings(): array
    {
        try {
            WebhooksConfig::schemes();
            WebhooksConfig::handlers();
            WebhooksConfig::sanitizer();
        } catch (Throwable $exception) {
            return [DoctorFinding::error('config', $exception->getMessage())];
        }

        return [...$this->queue(), ...$this->stores(), ...$this->safety(), ...$this->endpoints()];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function queue(): array
    {
        $findings = [];
        $connection = WebhooksConfig::queueConnection() ?? config('queue.default');
        $settings = is_string($connection) ? config("queue.connections.{$connection}") : null;
        $driver = is_array($settings) ? ($settings['driver'] ?? null) : null;
        $retryAfter = is_array($settings) ? ($settings['retry_after'] ?? null) : null;

        if ($driver === 'sync') {
            $findings[] = DoctorFinding::warning('sync_queue');
        }

        if (is_numeric($retryAfter) && (int) $retryAfter <= (new DeliverWebhook(0, 'doctor'))->timeout) {
            $findings[] = DoctorFinding::error('retry_after');
        }

        if (is_numeric($retryAfter) && (int) $retryAfter <= WebhooksConfig::handlerTimeout()) {
            $findings[] = DoctorFinding::error('handler_retry_after');
        }

        if ($driver === 'sqs' && WebhooksConfig::maxDelay() > self::SQS_MAX_DELAY) {
            $findings[] = DoctorFinding::error('max_delay_over_driver_limit');
        }

        if (! WebhooksConfig::scheduleEnabled()) {
            $findings[] = DoctorFinding::warning('schedule_disabled');
        }

        return $findings;
    }

    /**
     * Stores that silently stop working across processes: the throttles and the dedupe.
     *
     * @return list<DoctorFinding>
     */
    private function stores(): array
    {
        $findings = [];
        $connection = WebhooksConfig::queueConnection() ?? config('queue.default');
        $realQueue = is_string($connection) && config("queue.connections.{$connection}.driver") !== 'sync';

        if ($realQueue && config('http-client-rate-limits.store') === InMemoryStore::class) {
            $findings[] = DoctorFinding::warning('rate_limit_store');
        }

        if ($realQueue && self::cacheDriver(WebhooksConfig::rateLimitCacheStore()) === 'array') {
            $findings[] = DoctorFinding::warning('limiter_store_volatile');
        }

        $store = config('sentinel.idempotency.cache_store');

        if (config('sentinel.idempotency.store') === 'cache' && self::cacheDriver(is_string($store) && $store !== '' ? $store : null) === 'array') {
            $findings[] = DoctorFinding::error('idempotency_store_volatile');
        }

        if (config('sentinel.idempotency.store') === 'database' && ! Schema::hasTable('sentinel_idempotency_keys')) {
            $findings[] = DoctorFinding::error('idempotency_table');
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function safety(): array
    {
        $findings = [];

        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            $findings[] = DoctorFinding::error('curl');
        }

        if (! WebhooksConfig::ssrfEnabled()) {
            $findings[] = DoctorFinding::warning('ssrf_disabled');
        }

        if (WebhooksConfig::allowHttp() && app()->environment('production')) {
            $findings[] = DoctorFinding::warning('http_in_production');
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function endpoints(): array
    {
        $findings = [];

        if (! Schema::hasTable(WebhooksConfig::inboundEndpointsTable()) || ! Schema::hasTable(WebhooksConfig::outboundEndpointsTable())) {
            return [DoctorFinding::error('tables')];
        }

        foreach (ModelSeams::inboundEndpoint()::query()->cursor() as $endpoint) {
            $key = (string) Keys::of($endpoint);

            if (! $this->schemes->has($endpoint->signature_scheme)) {
                $findings[] = DoctorFinding::error('unknown_scheme', $key);
            }

            if ($endpoint->handler !== null && ! $this->handlers->has($endpoint->handler)) {
                $findings[] = DoctorFinding::error('unknown_handler', $key);
            }

            if ($endpoint->signature_scheme === 'rfc9421' && ! self::profileCoversId($endpoint->scheme_options ?? [])) {
                $findings[] = DoctorFinding::warning('rfc9421_id_unsigned', $key);
            }

            if ($endpoint->signature_scheme === 'rfc9421' && ! $this->hasBoundKey($endpoint)) {
                $findings[] = DoctorFinding::warning('rfc9421_no_key', $key);
            }
        }

        foreach (ModelSeams::outboundEndpoint()::query()->cursor() as $endpoint) {
            if (! $this->schemes->has($endpoint->signature_scheme)) {
                $findings[] = DoctorFinding::error('unknown_scheme', (string) Keys::of($endpoint));
            }
        }

        $stalled = $this->stalled();

        return $stalled === 0 ? $findings : [...$findings, DoctorFinding::error('sweep_stalled', (string) $stalled)];
    }

    /**
     * Rows only `webhooks:retry` moves, due for longer than it allows: the sweep is not running.
     */
    private function stalled(): int
    {
        $before = Clock::format(Clock::now()->subMinutes(self::STALL_MINUTES));

        $outbound = ModelSeams::outboundDelivery()::query()
            ->whereIn('status', [OutboundStatus::Pending->value, OutboundStatus::Retrying->value])
            ->whereNull('dispatch_token')
            ->where('next_attempt_at', '<', $before)
            ->count();

        $inbound = ModelSeams::inboundDelivery()::query()
            ->where('status', InboundStatus::Received->value)
            ->whereNull('queued_at')
            ->where('received_at', '<', $before)
            ->count();

        return $outbound + $inbound;
    }

    private static function cacheDriver(?string $store): ?string
    {
        $store ??= config('cache.default');
        $driver = is_string($store) ? config("cache.stores.{$store}.driver") : null;

        return is_string($driver) ? $driver : null;
    }

    /**
     * Whether a sentinel key in the endpoint's profile ring is bound to the endpoint.
     */
    private function hasBoundKey(InboundEndpoint $endpoint): bool
    {
        $options = $endpoint->scheme_options ?? [];
        $profile = $options['profile'] ?? WebhooksConfig::rfc9421Profile();
        $ring = is_string($profile) ? config("sentinel.signatures.profiles.{$profile}.ring") : null;

        try {
            $keys = $this->sentinel->keys()->ring(is_string($ring) ? $ring : null)->all();
        } catch (Throwable) {
            return false;
        }

        foreach ($keys as $key) {
            if ($key->ownerType === $endpoint->getMorphClass() && Keys::string($key->ownerId) === Keys::string($endpoint->getKey())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    private static function profileCoversId(array $options): bool
    {
        $profile = $options['profile'] ?? WebhooksConfig::rfc9421Profile();
        $header = $options['id_header'] ?? Rfc9421Scheme::DEFAULT_ID_HEADER;
        $components = is_string($profile) ? config("sentinel.signatures.profiles.{$profile}.components") : null;

        return is_array($components) && is_string($header) && in_array(strtolower($header), array_map(static fn (mixed $c): string => is_string($c) ? strtolower($c) : '', $components), true);
    }
}
