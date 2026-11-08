<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * A receiver answered an outbound delivery with a 2xx.
 * Safe for queued listeners: its models are re-fetched when the listener runs.
 */
final readonly class DeliverySucceeded
{
    use SerializesModels;

    public function __construct(
        public OutboundDelivery $delivery,
        public OutboundAttempt $attempt,
    ) {}
}
