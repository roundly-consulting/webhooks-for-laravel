<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;

/**
 * Cancel an endpoint's pending and retrying deliveries (a row mid-attempt finishes, and its next
 * attempt finds the endpoint disabled). Returns how many were cancelled.
 *
 * @internal
 */
final readonly class CancelPendingDeliveriesAction
{
    public function execute(OutboundEndpoint $endpoint): int
    {
        return ModelSeams::outboundDelivery()::query()
            ->where('endpoint_id', $endpoint->getKey())
            ->whereIn('status', [OutboundStatus::Pending->value, OutboundStatus::Retrying->value])
            ->update([
                'status' => OutboundStatus::Cancelled->value,
                'dispatch_token' => null,
                'next_attempt_at' => null,
                'updated_at' => Clock::format(Clock::now()),
            ]);
    }
}
