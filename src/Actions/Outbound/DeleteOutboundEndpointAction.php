<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * Soft-delete an outbound endpoint and cancel its pending deliveries. The delivery log stays
 * until retention removes it.
 */
final readonly class DeleteOutboundEndpointAction
{
    public function __construct(private CancelPendingDeliveriesAction $cancel) {}

    public function execute(OutboundEndpoint $endpoint): void
    {
        $endpoint->delete();
        $this->cancel->execute($endpoint);
    }
}
