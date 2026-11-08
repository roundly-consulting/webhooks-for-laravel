<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use RoundlyConsulting\Webhooks\Models\InboundEndpoint;

/**
 * Soft-delete an inbound endpoint: its URL answers 404 from now on; its delivery log stays until
 * retention removes it.
 */
final readonly class DeleteInboundEndpointAction
{
    public function execute(InboundEndpoint $endpoint): void
    {
        $endpoint->delete();
    }
}
