<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Webhooks\Enums\Direction;

/**
 * An endpoint's secret was rotated. Carries no secret.
 * Safe for queued listeners: its models are re-fetched when the listener runs.
 */
final readonly class EndpointSecretRotated
{
    use SerializesModels;

    public function __construct(
        public Model $endpoint,
        public Direction $direction,
        public CarbonImmutable $graceEndsAt,
    ) {}
}
