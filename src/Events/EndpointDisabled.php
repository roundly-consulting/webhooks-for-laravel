<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * An outbound endpoint was disabled — after consecutive failed deliveries, a 410 Gone, or by hand.
 */
final readonly class EndpointDisabled
{
    public function __construct(
        public OutboundEndpoint $endpoint,
        public DisableReason $reason,
    ) {}
}
