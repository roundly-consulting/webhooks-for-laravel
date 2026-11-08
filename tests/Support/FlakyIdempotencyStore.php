<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Sentinel\Contracts\IdempotencyStore;
use RoundlyConsulting\Sentinel\DataTransferObjects\IdempotencyDecision;
use RoundlyConsulting\Sentinel\DataTransferObjects\IdempotentRequest;
use RoundlyConsulting\Sentinel\Idempotency\ResponseSnapshot;
use RuntimeException;

/**
 * An idempotency store whose `complete()` fails once — on the n-th result it is asked to keep —
 * as a store hiccup after the callback ran would; `failOn: 0` fails `begin()` instead.
 */
final class FlakyIdempotencyStore implements IdempotencyStore
{
    private int $results = 0;

    public function __construct(private readonly IdempotencyStore $inner, private readonly int $failOn = 1) {}

    public function begin(IdempotentRequest $request): IdempotencyDecision
    {
        if ($this->failOn === 0) {
            throw new RuntimeException('The idempotency store is unavailable.');
        }

        return $this->inner->begin($request);
    }

    public function complete(IdempotentRequest $request, ResponseSnapshot $snapshot): bool
    {
        if ($snapshot->replayable && ++$this->results === $this->failOn) {
            throw new RuntimeException('The idempotency store is unavailable.');
        }

        return $this->inner->complete($request, $snapshot);
    }

    public function release(IdempotentRequest $request): void
    {
        $this->inner->release($request);
    }

    public function forget(string $keyDigest): bool
    {
        return $this->inner->forget($keyDigest);
    }

    public function prune(CarbonImmutable $now): int
    {
        return $this->inner->prune($now);
    }
}
