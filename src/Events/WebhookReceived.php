<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;

/**
 * A verified delivery is being processed — on the queue, before the endpoint's handler runs.
 * The payload is sanitized and untrusted.
 */
final readonly class WebhookReceived
{
    public function __construct(public InboundWebhook $webhook) {}
}
