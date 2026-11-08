<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * One delivery `Webhooks::fake()` recorded instead of queueing it.
 */
final readonly class SentWebhook
{
    /**
     * @param  array<array-key, mixed>  $payload  the payload as sent (decoded from the body built for it)
     */
    public function __construct(
        public OutboundEndpoint $endpoint,
        public string $eventType,
        public array $payload,
        public string $body,
        public bool $broadcast,
        public ?Model $owner,
        public string $webhookId,
    ) {}
}
