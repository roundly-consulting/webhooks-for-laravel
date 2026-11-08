<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Enums\Direction;

/**
 * Where a payload is being sanitized: its direction, its endpoint and its event type.
 */
final readonly class SanitizeContext
{
    public function __construct(
        public Direction $direction,
        public Model $endpoint,
        public ?string $eventType,
    ) {}
}
