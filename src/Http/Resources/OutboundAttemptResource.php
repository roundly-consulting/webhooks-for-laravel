<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;

/**
 * One attempt of an outbound delivery.
 *
 * @property OutboundAttempt $resource
 */
final class OutboundAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attempt = $this->resource;

        return [
            'number' => $attempt->number,
            'series' => $attempt->series,
            'manual' => $attempt->manual,
            'started_at' => $attempt->started_at->toIso8601ZuluString(),
            'duration_ms' => $attempt->duration_ms,
            'response_status' => $attempt->response_status,
            'error_code' => $attempt->error_code?->value,
            'error_message' => $attempt->error_message,
            'retry_after_seconds' => $attempt->retry_after_seconds,
        ];
    }
}
