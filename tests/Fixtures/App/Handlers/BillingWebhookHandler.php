<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers;

use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models\HandledWebhook;

/**
 * Counts its runs in a table, so a test sees how often it ran — across processes too.
 */
final class BillingWebhookHandler implements InboundHandler
{
    public function handle(InboundWebhook $webhook): void
    {
        HandledWebhook::query()->create([
            'delivery_id' => $webhook->deliveryId,
            'type' => $webhook->type,
            'replay' => $webhook->isReplay,
            'run' => $webhook->run,
        ]);
    }
}
