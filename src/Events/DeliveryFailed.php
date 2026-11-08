<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * An outbound attempt failed. `final` when no further attempt will follow.
 */
final readonly class DeliveryFailed
{
    public function __construct(
        public OutboundDelivery $delivery,
        public OutboundAttempt $attempt,
        public bool $final,
        public ?CarbonImmutable $nextAttemptAt,
    ) {}
}
