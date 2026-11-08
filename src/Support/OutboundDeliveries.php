<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use Symfony\Component\Uid\Ulid;

/**
 * Stores a built body as a pending outbound delivery and dispatches its first attempt — what a
 * send and a ping share.
 *
 * @internal
 */
final readonly class OutboundDeliveries
{
    public function __construct(private Queues $queues) {}

    public function store(OutboundEndpoint $endpoint, string $eventType, string $body, ?string $publicId = null): OutboundDelivery
    {
        $publicId ??= self::publicId($endpoint, null);
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
            // A savepoint: a racing idempotent twin must not abort the caller's transaction (Postgres).
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

    public static function existing(string $publicId): ?OutboundDelivery
    {
        return ModelSeams::outboundDelivery()::query()->withTrashed()->where('public_id', $publicId)->first();
    }
}
