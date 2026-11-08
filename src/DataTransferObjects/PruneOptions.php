<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonInterface;

final readonly class PruneOptions
{
    public function __construct(
        public bool $dryRun = false,
        public ?CarbonInterface $now = null,
    ) {}
}
