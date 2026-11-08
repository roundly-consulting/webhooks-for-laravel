<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Run an inbound delivery's handler again (by its public id), flagged as a replay.
 */
final class ReplayCommand extends Command
{
    protected $signature = 'webhooks:replay {delivery : The delivery id}';

    protected $description = 'Replay an inbound webhook';

    public function handle(WebhooksManager $webhooks): int
    {
        $id = $this->argument('delivery');
        $delivery = $webhooks->inbound()->deliveries()->where('public_id', $id)->first();

        if ($delivery === null) {
            $this->components->error((string) trans('webhooks::commands.delivery_not_found'));

            return self::FAILURE;
        }

        $webhooks->inbound()->replay($delivery);
        $this->components->info((string) trans('webhooks::commands.replayed'));

        return self::SUCCESS;
    }
}
