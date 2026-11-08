<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;

/**
 * An endpoint's handler failed on its last try.
 * Safe for queued listeners: its models are re-fetched when the listener runs.
 */
final readonly class WebhookProcessingFailed
{
    use SerializesModels;

    public function __construct(
        public InboundDelivery $delivery,
        public string $errorCode,
    ) {}
}
