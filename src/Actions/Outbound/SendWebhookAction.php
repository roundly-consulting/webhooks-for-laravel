<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OutboundMessages;
use RoundlyConsulting\Webhooks\Support\Queues;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Queue one event for one endpoint: the body is built once (byte-identical on every attempt),
 * stored encrypted, and the first attempt is dispatched after the current transaction commits —
 * a rolled-back transaction sends nothing.
 */
final readonly class SendWebhookAction
{
    public function __construct(private Queues $queues) {}

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     *
     * @throws EndpointNotAcceptingException|InvalidEventTypeException|PayloadTooLargeException
     */
    public function execute(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload): OutboundDelivery
    {
        $body = OutboundMessages::body($endpoint, $eventType, $payload);
        $class = ModelSeams::outboundDelivery();

        $delivery = new $class;
        $delivery->forceFill([
            'public_id' => (string) Str::ulid(),
            'endpoint_id' => $endpoint->getKey(),
            'event_type' => $eventType,
            'body' => $body,
            'body_bytes' => strlen($body),
            'body_digest' => Crypto::digest()->hex($body),
            'status' => OutboundStatus::Pending,
            'attempts' => 0,
            'series' => 1,
            'max_attempts' => WebhooksConfig::maxAttempts(),
        ])->save();

        $this->queues->outbound($delivery);

        return $delivery;
    }
}
