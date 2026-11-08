<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Webhooks\Contracts\PayloadSanitizer;
use RoundlyConsulting\Webhooks\DataTransferObjects\AcceptedDelivery;
use RoundlyConsulting\Webhooks\DataTransferObjects\SanitizeContext;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\Direction;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\PayloadSanitizerException;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\Queues;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use Throwable;

/**
 * Store a verified delivery (sanitized payload, allowlisted headers) and queue its processing.
 * Runs inside the idempotency run, so it happens once per delivery id. The unique
 * `(endpoint_id, idempotency_key)` index is the backstop when the idempotency store forgot the
 * id: the stored delivery is returned instead of a second one.
 *
 * @internal
 */
final readonly class AcceptInboundDeliveryAction
{
    public function __construct(
        private PayloadSanitizer $sanitizer,
        private Queues $queues,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload  the decoded body
     * @param  array<string, string>  $headers  lower-cased request headers
     *
     * @throws PayloadSanitizerException
     */
    public function execute(InboundEndpoint $endpoint, VerifiedWebhook $verified, string $idempotencyKey, array $payload, array $headers, string $body): AcceptedDelivery
    {
        $existing = $this->stored($endpoint, $idempotencyKey);

        if ($existing !== null) {
            return new AcceptedDelivery($existing->public_id, true);
        }

        $eventType = $verified->eventType ?? (isset($payload['type']) && is_string($payload['type']) ? mb_substr($payload['type'], 0, 191) : null);
        try {
            $sanitized = $this->sanitizer->sanitize($payload, new SanitizeContext(Direction::Inbound, $endpoint, $eventType));
        } catch (Throwable $exception) {
            // Never store (or hand on) an unsanitized payload.
            report($exception);

            throw PayloadSanitizerException::from($exception);
        }
        $class = ModelSeams::inboundDelivery();
        $now = Clock::now();

        $delivery = new $class;
        $delivery->forceFill([
            'public_id' => (string) Str::ulid(),
            'endpoint_id' => $endpoint->getKey(),
            'idempotency_key' => $idempotencyKey,
            'external_id' => $verified->deliveryId === null ? null : mb_substr($verified->deliveryId, 0, 255),
            'event_type' => $eventType,
            'status' => InboundStatus::Received,
            'headers' => self::storedHeaders($headers),
            'payload' => $sanitized,
            'payload_bytes' => strlen($body),
            'payload_digest' => Crypto::digest()->hex($body),
            'signature_slot' => $verified->slot,
            'received_at' => $now,
        ]);

        try {
            $delivery->save();
        } catch (UniqueConstraintViolationException) {
            $existing = $this->stored($endpoint, $idempotencyKey);

            return new AcceptedDelivery($existing === null ? '' : $existing->public_id, true);
        }

        $endpoint->forceFill(['last_received_at' => $now])->saveQuietly();
        $this->queue($delivery);

        return new AcceptedDelivery($delivery->public_id, false);
    }

    private function stored(InboundEndpoint $endpoint, string $idempotencyKey): ?InboundDelivery
    {
        return ModelSeams::inboundDelivery()::query()
            ->where('endpoint_id', $endpoint->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * A delivery stored but never queued (the queue was down) is queued by its duplicate.
     */
    public function requeueStalled(InboundEndpoint $endpoint, string $idempotencyKey): void
    {
        $delivery = $this->stored($endpoint, $idempotencyKey);

        if ($delivery !== null && $delivery->status === InboundStatus::Received && $delivery->queued_at === null) {
            $this->queue($delivery);
        }
    }

    private function queue(InboundDelivery $delivery): void
    {
        try {
            $this->queues->inbound($delivery);
        } catch (Throwable $exception) {
            // Stored but not queued: the sweep (webhooks:retry) queues it after inbound.requeue_after.
            report($exception);

            return;
        }

        // Conditional: on a sync queue the job already ran and moved the status on.
        $query = ModelSeams::inboundDelivery()::query()->whereKey($delivery->getKey());
        (clone $query)->update(['queued_at' => Clock::format(Clock::now())]);
        $query->where('status', InboundStatus::Received->value)->update(['status' => InboundStatus::Queued->value]);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function storedHeaders(array $headers): array
    {
        $stored = [];

        foreach (WebhooksConfig::storedHeaders() as $name) {
            if (isset($headers[$name])) {
                $stored[$name] = mb_substr($headers[$name], 0, 1000);
            }
        }

        return $stored;
    }
}
