<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\HttpClientRateLimits\Store\InMemoryStore;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use Throwable;

/**
 * What `webhooks:doctor` checks: settings that silently weaken delivery or safety.
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

    /**
     * @return list<array{level: string, check: string, detail?: string}> level `error` or `warning`
     */
    public function findings(): array
    {
        $findings = [];

        try {
            WebhooksConfig::schemes();
            WebhooksConfig::handlers();
            WebhooksConfig::sanitizer();
        } catch (Throwable $exception) {
            $findings[] = ['level' => 'error', 'check' => 'config', 'detail' => $exception->getMessage()];

            return $findings;
        }

        $connection = WebhooksConfig::queueConnection() ?? config('queue.default');
        $driver = is_string($connection) ? config("queue.connections.{$connection}.driver") : null;

        if ($driver === 'sync') {
            $findings[] = ['level' => 'warning', 'check' => 'sync_queue'];
        }

        if ($driver !== 'sync' && config('http-client-rate-limits.store') === InMemoryStore::class) {
            $findings[] = ['level' => 'warning', 'check' => 'rate_limit_store'];
        }

        $retryAfter = is_string($connection) ? config("queue.connections.{$connection}.retry_after") : null;

        if ($driver !== 'sync' && is_numeric($retryAfter) && (int) $retryAfter <= (new DeliverWebhook(0, 'doctor'))->timeout) {
            $findings[] = ['level' => 'error', 'check' => 'retry_after'];
        }

        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            $findings[] = ['level' => 'error', 'check' => 'curl'];
        }

        if (! WebhooksConfig::ssrfEnabled()) {
            $findings[] = ['level' => 'warning', 'check' => 'ssrf_disabled'];
        }

        if (WebhooksConfig::allowHttp() && app()->environment('production')) {
            $findings[] = ['level' => 'warning', 'check' => 'http_in_production'];
        }

        if (! Schema::hasTable('sentinel_idempotency_keys') && config('sentinel.idempotency.store') === 'database') {
            $findings[] = ['level' => 'error', 'check' => 'idempotency_table'];
        }

        return [...$findings, ...$this->endpoints()];
    }

    /**
     * @return list<array{level: string, check: string, detail?: string}>
     */
    private function endpoints(): array
    {
        $findings = [];

        if (! Schema::hasTable(WebhooksConfig::inboundEndpointsTable()) || ! Schema::hasTable(WebhooksConfig::outboundEndpointsTable())) {
            return [['level' => 'error', 'check' => 'tables']];
        }

        foreach (ModelSeams::inboundEndpoint()::query()->cursor() as $endpoint) {
            if (! $this->schemes->has($endpoint->signature_scheme)) {
                $findings[] = ['level' => 'error', 'check' => 'unknown_scheme', 'detail' => (string) Keys::of($endpoint)];
            }

            if ($endpoint->handler !== null && ! $this->handlers->has($endpoint->handler)) {
                $findings[] = ['level' => 'error', 'check' => 'unknown_handler', 'detail' => (string) Keys::of($endpoint)];
            }

            if ($endpoint->signature_scheme === 'rfc9421' && ! self::profileCoversId($endpoint->scheme_options ?? [])) {
                $findings[] = ['level' => 'warning', 'check' => 'rfc9421_id_unsigned', 'detail' => (string) Keys::of($endpoint)];
            }

            if ($endpoint->signature_scheme === 'rfc9421' && ! $this->hasBoundKey($endpoint)) {
                $findings[] = ['level' => 'warning', 'check' => 'rfc9421_no_key', 'detail' => (string) Keys::of($endpoint)];
            }
        }

        foreach (ModelSeams::outboundEndpoint()::query()->cursor() as $endpoint) {
            if (! $this->schemes->has($endpoint->signature_scheme)) {
                $findings[] = ['level' => 'error', 'check' => 'unknown_scheme', 'detail' => (string) Keys::of($endpoint)];
            }
        }

        return $findings;
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
