<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * An outbound endpoint was disabled — after consecutive failed deliveries, a 410 Gone, or by hand.
 * Safe for queued listeners: its models are re-fetched when the listener runs.
 */
final readonly class EndpointDisabled
{
    use SerializesModels;

    public function __construct(
        public OutboundEndpoint $endpoint,
        public DisableReason $reason,
    ) {}
}
