<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Clock;

/**
 * Enable or disable an inbound endpoint. A disabled endpoint answers 404, exactly like an
 * unknown one.
 */
final readonly class SetInboundEndpointStateAction
{
    public function execute(InboundEndpoint $endpoint, bool $enabled): InboundEndpoint
    {
        $endpoint->forceFill($enabled
            ? ['enabled' => true, 'disabled_at' => null, 'disabled_reason' => null]
            : ['enabled' => false, 'disabled_at' => Clock::now(), 'disabled_reason' => DisableReason::Manual])->save();

        return $endpoint;
    }
}
