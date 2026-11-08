<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use Carbon\CarbonImmutable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * An outbound attempt failed. `final` when no further attempt will follow.
 * Safe for queued listeners: its models are re-fetched when the listener runs.
 */
final readonly class DeliveryFailed
{
    use SerializesModels;

    public function __construct(
        public OutboundDelivery $delivery,
        public OutboundAttempt $attempt,
        public bool $final,
        public ?CarbonImmutable $nextAttemptAt,
    ) {}
}
