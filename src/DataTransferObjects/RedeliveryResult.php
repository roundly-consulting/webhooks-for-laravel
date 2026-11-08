<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * What a bulk redelivery did: how many deliveries were queued again, and how many were skipped
 * because retention had already cleared their body.
 */
final readonly class RedeliveryResult
{
    public function __construct(
        public int $queued,
        public int $skippedPruned,
    ) {}
}
