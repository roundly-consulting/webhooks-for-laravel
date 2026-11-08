<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Events;

use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;

/**
 * A verified delivery is being processed — on the queue, before the endpoint's handler runs.
 * The payload is sanitized and untrusted.
 *
 * Fired on every attempt of a run: a handler that throws is retried, and the retry fires this
 * again (`$event->webhook->attempt`, `isFirstAttempt()`). A queued listener stores the payload in
 * the queue unencrypted — queue only the `deliveryId` and re-load the delivery instead.
 */
final readonly class WebhookReceived
{
    public function __construct(public InboundWebhook $webhook) {}
}
