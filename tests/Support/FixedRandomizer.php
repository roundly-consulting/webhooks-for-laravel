<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Support;

use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;

/**
 * Jitter a test can predict: always the same value (clamped into the asked range).
 */
final class FixedRandomizer implements Randomizer
{
    public function __construct(private readonly int $value) {}

    public function between(int $min, int $max): int
    {
        return max($min, min($max, $this->value));
    }
}
