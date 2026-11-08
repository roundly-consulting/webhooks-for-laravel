<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * Enable or disable an outbound endpoint. Disabling fires `EndpointDisabled` (reason `manual`)
 * and cancels pending deliveries; enabling resets the failure counter but does not resend what
 * was cancelled (redeliver it).
 */
final readonly class SetOutboundEndpointStateAction
{
    public function __construct(private DisableOutboundEndpointAction $disable) {}

    public function execute(OutboundEndpoint $endpoint, bool $enabled): OutboundEndpoint
    {
        if (! $enabled) {
            $this->disable->execute($endpoint, DisableReason::Manual);

            return $endpoint;
        }

        $endpoint->forceFill([
            'enabled' => true,
            'disabled_at' => null,
            'disabled_reason' => null,
            'consecutive_failures' => 0,
        ])->save();

        return $endpoint;
    }
}
