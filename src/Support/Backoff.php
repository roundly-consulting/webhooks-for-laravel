<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;

/**
 * How long to wait before the next attempt: `min(base · multiplier^(n-1), cap)` (or the explicit
 * schedule), ± jitter, never under a second — raised to a receiver's `Retry-After` (capped).
 *
 * @internal
 */
final readonly class Backoff
{
    public function __construct(private Randomizer $randomizer) {}

    /**
     * @param  int  $failedAttempt  the number of the attempt that just failed (1-based)
     */
    public function delay(int $failedAttempt, ?int $retryAfter = null): int
    {
        $n = max(1, $failedAttempt);
        $schedule = WebhooksConfig::retrySchedule();

        if ($schedule !== null) {
            $base = $schedule[min($n, count($schedule)) - 1];
        } else {
            $base = WebhooksConfig::retryBase();
            $multiplier = WebhooksConfig::retryMultiplier();
            $cap = WebhooksConfig::retryCap();

            for ($i = 1; $i < $n && $base < $cap; $i++) {
                $base *= $multiplier;
            }

            $base = min($base, $cap);
        }

        $permille = WebhooksConfig::jitterPermille();
        $jitter = $permille === 0 ? 0 : $this->randomizer->between(-$permille, $permille);
        $delay = max(1, intdiv($base * (1000 + $jitter), 1000));

        if ($retryAfter !== null && $retryAfter > 0) {
            $delay = max($delay, min($retryAfter, WebhooksConfig::maxRetryAfter()));
        }

        return $delay;
    }
}
