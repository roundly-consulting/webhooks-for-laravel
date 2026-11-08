<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * The sweep (every minute): dispatch due, parked and lost outbound deliveries, recover attempts
 * whose worker died, and queue inbound deliveries that were stored but never queued.
 */
final class RetryCommand extends Command
{
    protected $signature = 'webhooks:retry';

    protected $description = 'Dispatch due webhook deliveries';

    public function handle(WebhooksManager $webhooks): int
    {
        $count = $webhooks->outbound()->dispatchDue();

        $this->components->info((string) trans('webhooks::commands.dispatched', ['count' => $count]));

        return self::SUCCESS;
    }
}
