<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers;

use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RuntimeException;

final class FailingHandler implements InboundHandler
{
    public function handle(InboundWebhook $webhook): void
    {
        throw new RuntimeException('ledger down');
    }
}
