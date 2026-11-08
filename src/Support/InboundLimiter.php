<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * The two inbound limiters (per endpoint + IP for rejections, per endpoint for verified
 * deliveries), on the configured cache store. A rate-limited rejection is announced once per
 * window: a flood past the limit must not cost one listener run per request.
 *
 * @internal
 */
final readonly class InboundLimiter
{
    private const int WINDOW = 60;

    public function __construct(private CacheFactory $cache) {}

    public function rejectionsExhausted(int|string $endpointId, string $ip): ?int
    {
        $limiter = $this->limiter();
        $key = self::rejectionKey($endpointId, $ip);

        return $limiter->tooManyAttempts($key, WebhooksConfig::inboundRejectionLimit()) ? max(1, $limiter->availableIn($key)) : null;
    }

    public function hitRejection(int|string $endpointId, string $ip): void
    {
        $this->limiter()->hit(self::rejectionKey($endpointId, $ip), self::WINDOW);
    }

    /**
     * Count one verified delivery; the seconds to wait when the endpoint is over its limit.
     */
    public function attemptDelivery(int|string $endpointId, int $perMinute): ?int
    {
        $limiter = $this->limiter();
        $key = 'webhooks:in:'.$endpointId;

        if ($limiter->tooManyAttempts($key, $perMinute)) {
            return max(1, $limiter->availableIn($key));
        }

        $limiter->hit($key, self::WINDOW);

        return null;
    }

    /**
     * True the first time in a window — whether to announce a rate-limited rejection.
     */
    public function firstInWindow(string $flag): bool
    {
        return $this->store()->add('webhooks:in:announced:'.$flag, true, self::WINDOW);
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->store());
    }

    private function store(): Repository
    {
        return $this->cache->store(WebhooksConfig::rateLimitCacheStore());
    }

    private static function rejectionKey(int|string $endpointId, string $ip): string
    {
        return 'webhooks:in:rej:'.$endpointId.':'.$ip;
    }
}
