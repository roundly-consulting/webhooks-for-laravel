<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * Validates an outbound send and builds its body once: the envelope
 * `{"type", "timestamp", "data"}` (or the payload as is), JSON-encoded with stable flags. The
 * stored body is byte-identical on every attempt. Shared by production and the fake, so the
 * fake refuses exactly what production refuses.
 *
 * @internal
 */
final class OutboundMessages
{
    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     *
     * @throws EndpointNotAcceptingException|PayloadTooLargeException
     */
    public static function body(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload): string
    {
        EventTypes::assertSendable($eventType);

        if (! $endpoint->enabled || $endpoint->trashed()) {
            throw EndpointNotAcceptingException::disabled();
        }

        if (! $endpoint->subscribesTo($eventType)) {
            throw EndpointNotAcceptingException::notSubscribed($eventType);
        }

        return self::encode($eventType, $payload);
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     *
     * @throws PayloadTooLargeException
     */
    public static function encode(string $eventType, array|Arrayable|JsonSerializable $payload): string
    {
        $data = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        $message = WebhooksConfig::envelope()
            ? ['type' => $eventType, 'timestamp' => Clock::now()->format('Y-m-d\TH:i:s\Z'), 'data' => $data]
            : $data;

        $body = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $limit = WebhooksConfig::outboundMaxPayloadBytes();

        if (strlen($body) > $limit) {
            throw PayloadTooLargeException::make(strlen($body), $limit);
        }

        return $body;
    }
}
