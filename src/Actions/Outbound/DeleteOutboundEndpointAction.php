<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * Soft-delete an outbound endpoint and cancel its pending deliveries. Its secrets, URL and
 * custom headers are wiped at once (the host name stays for the log). The delivery log stays
 * until retention removes it; `webhooks:prune` then removes the endpoint row too.
 */
final readonly class DeleteOutboundEndpointAction
{
    public function __construct(private CancelPendingDeliveriesAction $cancel) {}

    public function execute(OutboundEndpoint $endpoint): void
    {
        $endpoint->getConnection()->transaction(static function () use ($endpoint): void {
            $endpoint->forceFill(['secret' => '', 'previous_secret' => null, 'previous_secret_expires_at' => null, 'url' => '', 'headers' => null])->save();
            $endpoint->delete();
        });

        $this->cancel->execute($endpoint);
    }
}
