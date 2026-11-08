<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Contracts;

use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;

/**
 * Handles an endpoint's verified deliveries on the queue, once per delivery (and once more per
 * replay, with `$webhook->isReplay`). The payload is untrusted input: validate before use.
 */
interface InboundHandler
{
    public function handle(InboundWebhook $webhook): void;
}
