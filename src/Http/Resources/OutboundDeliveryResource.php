<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * An outbound delivery for a host's delivery log (its `msg_` id, status and retry state).
 *
 * @property OutboundDelivery $resource
 */
final class OutboundDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $delivery = $this->resource;

        return [
            'id' => $delivery->webhookId(),
            'endpoint_id' => $delivery->endpoint_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'series' => $delivery->series,
            'max_attempts' => $delivery->max_attempts,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601ZuluString(),
            'last_response_status' => $delivery->last_response_status,
            'last_error_code' => $delivery->last_error_code?->value,
            'succeeded_at' => $delivery->succeeded_at?->toIso8601ZuluString(),
            'failed_at' => $delivery->failed_at?->toIso8601ZuluString(),
            'created_at' => $delivery->created_at?->toIso8601ZuluString(),
        ];
    }
}
