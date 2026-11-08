<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Enums\Direction;

/**
 * An endpoint's secret was rotated. Carries no secret.
 */
final readonly class EndpointSecretRotated
{
    public function __construct(
        public Model $endpoint,
        public Direction $direction,
        public CarbonImmutable $graceEndsAt,
    ) {}
}
