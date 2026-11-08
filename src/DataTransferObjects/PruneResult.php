<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * What a prune cleared (or, on a dry run, would clear).
 */
final readonly class PruneResult
{
    public function __construct(
        public int $inboundPayloads,
        public int $outboundBodies,
        public int $attemptBodies,
        public int $inboundDeliveries,
        public int $outboundDeliveries,
        public int $attempts,
        public int $expiredSecrets,
        public bool $dryRun,
        public int $endpoints = 0,
    ) {}

    public function total(): int
    {
        return $this->inboundPayloads + $this->outboundBodies + $this->attemptBodies + $this->inboundDeliveries
            + $this->outboundDeliveries + $this->attempts + $this->expiredSecrets + $this->endpoints;
    }
}
