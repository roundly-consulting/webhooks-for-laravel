<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;

/**
 * An inbound delivery for a host's delivery log. The (sanitized) payload is included only after
 * `withPayload()`.
 *
 * @property InboundDelivery $resource
 */
final class InboundDeliveryResource extends JsonResource
{
    private bool $includePayload = false;

    public function withPayload(bool $include = true): self
    {
        $this->includePayload = $include;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $delivery = $this->resource;

        return [
            'id' => $delivery->public_id,
            'endpoint_id' => $delivery->endpoint_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status->value,
            'signature_slot' => $delivery->signature_slot?->value,
            'received_at' => $delivery->received_at->toIso8601ZuluString(),
            'processed_at' => $delivery->processed_at?->toIso8601ZuluString(),
            'failed_at' => $delivery->failed_at?->toIso8601ZuluString(),
            'runs' => $delivery->runs,
            'error_code' => $delivery->last_error_code,
            'error_message' => $delivery->last_error_message,
            'payload_pruned' => $delivery->payload_pruned_at !== null,
            'payload' => $this->when($this->includePayload, $delivery->payload),
        ];
    }
}
