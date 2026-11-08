<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use RoundlyConsulting\Webhooks\Enums\RejectionReason;

/**
 * An inbound request to a known endpoint was refused. Carries no headers and no body. Unknown
 * keys fire nothing; a rate-limited rejection fires once per limiter window.
 */
final readonly class WebhookRejected
{
    public function __construct(
        public int|string $endpointId,
        public RejectionReason $reason,
        public int $status,
        public ?string $ip,
        public ?string $userAgent,
    ) {}
}
