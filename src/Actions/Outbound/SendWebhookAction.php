<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use JsonSerializable;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Keys;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OutboundMessages;
use RoundlyConsulting\Webhooks\Support\Queues;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use Symfony\Component\Uid\Ulid;

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
    public function __construct(private Queues $queues) {}

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     *
     * @throws EndpointNotAcceptingException|InvalidEventTypeException|PayloadTooLargeException
     */
    public function execute(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload, ?string $id = null): OutboundDelivery
    {
        $body = OutboundMessages::body($endpoint, $eventType, $payload);
        $publicId = self::publicId($endpoint, $id);
        $existing = $id === null ? null : self::existing($publicId);

        if ($existing !== null) {
            return $existing;
        }

        $class = ModelSeams::outboundDelivery();

        $delivery = new $class;
        $delivery->forceFill([
            'public_id' => $publicId,
            'endpoint_id' => $endpoint->getKey(),
            'event_type' => $eventType,
            'body' => $body,
            'body_bytes' => strlen($body),
            'body_digest' => Crypto::digest()->hex($body),
            'status' => OutboundStatus::Pending,
            'attempts' => 0,
            'series' => 1,
            'max_attempts' => WebhooksConfig::maxAttempts(),
        ]);

        try {
            // A savepoint: a racing duplicate must not abort the caller's transaction (Postgres).
            $delivery->getConnection()->transaction(static fn () => $delivery->save());
        } catch (UniqueConstraintViolationException $exception) {
            return self::existing($publicId) ?? throw $exception;
        }

        $this->queues->outbound($delivery);

        return $delivery;
    }

    /**
     * A fresh ULID, or — for an idempotent send — one derived from the endpoint and the id.
     */
    public static function publicId(OutboundEndpoint $endpoint, ?string $id): string
    {
        if ($id === null) {
            return (string) Str::ulid();
        }

        $digest = Crypto::digest()->hex('webhooks:send:'.Keys::of($endpoint).':'.$id);

        return Ulid::fromBinary(Crypto::hexDecode(substr($digest, 0, 32)))->toBase32();
    }

    private static function existing(string $publicId): ?OutboundDelivery
    {
        return ModelSeams::outboundDelivery()::query()->withTrashed()->where('public_id', $publicId)->first();
    }
}
