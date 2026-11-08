<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;

/**
 * An inbound endpoint for a host's UI/API — never its secret. Eager-load `owner` to render it.
 *
 * @property InboundEndpoint $resource
 */
final class InboundEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $endpoint = $this->resource;

        return [
            'id' => $endpoint->getKey(),
            'name' => $endpoint->name,
            'url' => $endpoint->url(),
            'scheme' => $endpoint->signature_scheme,
            'handler' => $endpoint->handler,
            'enabled' => $endpoint->enabled,
            'disabled_reason' => $endpoint->disabled_reason?->value,
            'owner' => OwnerReference::render($endpoint, $request),
            'last_received_at' => $endpoint->last_received_at?->toIso8601ZuluString(),
            'secret_rotated_at' => $endpoint->secret_rotated_at?->toIso8601ZuluString(),
            'previous_secret_expires_at' => $endpoint->previous_secret_expires_at?->toIso8601ZuluString(),
            'created_at' => $endpoint->created_at?->toIso8601ZuluString(),
        ];
    }
}
