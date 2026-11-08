<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;

/**
 * What a handler receives: the sanitized payload (untrusted input — validate it), the stored
 * headers, and whether this run is a replay. Use `$deliveryId` as your own idempotency key for
 * side effects that must never repeat.
 */
final readonly class InboundWebhook
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $deliveryId,
        public InboundEndpoint $endpoint,
        public ?string $type,
        public array $payload,
        public array $headers,
        public CarbonImmutable $receivedAt,
        public bool $isReplay,
        public int $run,
        public int $attempt,
    ) {}

    /**
     * A payload value by dot path (`data.invoice.id`), or the default. Untrusted — validate it.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->payload, $path, $default);
    }

    /**
     * A stored request header (only those in `inbound.store_headers`), case-insensitive.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The model that owns the endpoint (a team, an account), or null for an app-level endpoint.
     */
    public function owner(): ?Model
    {
        $owner = $this->endpoint->owner;

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * Whether this is the first attempt of the run — `WebhookReceived` fires on every attempt,
     * the handler's retries included. A replay opens a new run, whose first attempt is first again.
     */
    public function isFirstAttempt(): bool
    {
        return $this->attempt <= 1;
    }
}
