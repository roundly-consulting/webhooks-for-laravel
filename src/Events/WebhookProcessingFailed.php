<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use RoundlyConsulting\Webhooks\Models\InboundDelivery;

/**
 * An endpoint's handler failed on its last try.
 */
final readonly class WebhookProcessingFailed
{
    public function __construct(
        public InboundDelivery $delivery,
        public string $errorCode,
    ) {}
}
