<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * An outbound endpoint for a host's UI/API — never its secret or header values, and its URL
 * redacted to `scheme://host[:port]/…` (a receiver URL may carry a token).
 *
 * @property OutboundEndpoint $resource
 */
final class OutboundEndpointResource extends JsonResource
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
            'description' => $endpoint->description,
            'url' => self::redact($endpoint->url),
            'host' => $endpoint->url_host,
            'event_types' => $endpoint->event_types,
            'headers' => array_keys($endpoint->headers ?? []),
            'scheme' => $endpoint->signature_scheme,
            'enabled' => $endpoint->enabled,
            'disabled_reason' => $endpoint->disabled_reason?->value,
            'consecutive_failures' => $endpoint->consecutive_failures,
            'last_success_at' => $endpoint->last_success_at?->toIso8601ZuluString(),
            'last_failure_at' => $endpoint->last_failure_at?->toIso8601ZuluString(),
            'owner' => OwnerReference::render($endpoint, $request),
            'created_at' => $endpoint->created_at?->toIso8601ZuluString(),
        ];
    }

    public static function redact(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '…';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/…';
    }
}
