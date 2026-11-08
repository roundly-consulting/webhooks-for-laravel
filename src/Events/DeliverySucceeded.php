<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * A receiver answered an outbound delivery with a 2xx.
 */
final readonly class DeliverySucceeded
{
    public function __construct(
        public OutboundDelivery $delivery,
        public OutboundAttempt $attempt,
    ) {}
}
