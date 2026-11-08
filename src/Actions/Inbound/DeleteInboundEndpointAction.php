<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use RoundlyConsulting\Webhooks\Models\InboundEndpoint;

/**
 * Soft-delete an inbound endpoint: its URL answers 404 from now on and its secrets are wiped at
 * once (a deleted endpoint is dead — undeleting it would need a new secret). The delivery log
 * stays until retention removes it; `webhooks:prune` then removes the endpoint row too. A
 * delivery still waiting to be processed fails (`endpoint_deleted`).
 */
final readonly class DeleteInboundEndpointAction
{
    public function execute(InboundEndpoint $endpoint): void
    {
        $endpoint->getConnection()->transaction(static function () use ($endpoint): void {
            $endpoint->forceFill(['secret' => null, 'previous_secret' => null, 'previous_secret_expires_at' => null])->save();
            $endpoint->delete();
        });
    }
}
