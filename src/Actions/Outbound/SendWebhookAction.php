<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\OutboundDeliveries;
use RoundlyConsulting\Webhooks\Support\OutboundMessages;

/**
 * Queue one event for one endpoint: the body is built once (byte-identical on every attempt),
 * stored encrypted, and the first attempt is dispatched after the current transaction commits —
 * a rolled-back transaction sends nothing.
 *
 * With an `$id` (yours — an event id, `invoice-42-paid`) the send is idempotent: the
 * `webhook-id` is derived from the endpoint and the id, and a repeat returns the delivery queued
 * the first time and queues nothing (for a listener that may run twice). The first body wins.
 */
final readonly class SendWebhookAction
{
    public function __construct(private OutboundDeliveries $deliveries) {}

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     *
     * @throws EndpointNotAcceptingException|InvalidEventTypeException|PayloadTooLargeException
     */
    public function execute(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload, ?string $id = null): OutboundDelivery
    {
        $body = OutboundMessages::body($endpoint, $eventType, $payload);
        $publicId = OutboundDeliveries::publicId($endpoint, $id);
        $existing = $id === null ? null : OutboundDeliveries::existing($publicId);

        if ($existing !== null) {
            return $existing;
        }

        return $this->deliveries->store($endpoint, $eventType, $body, $publicId);
    }
}
