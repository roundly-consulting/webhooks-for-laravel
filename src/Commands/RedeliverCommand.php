<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Send a finished outbound delivery again (by its `msg_…` id).
 */
final class RedeliverCommand extends Command
{
    protected $signature = 'webhooks:redeliver {delivery : The delivery id (msg_…)}';

    protected $description = 'Redeliver an outbound webhook';

    public function handle(WebhooksManager $webhooks): int
    {
        $id = $this->argument('delivery');
        $id = str_starts_with($id, OutboundDelivery::ID_PREFIX) ? substr($id, strlen(OutboundDelivery::ID_PREFIX)) : $id;
        $delivery = $webhooks->outbound()->deliveries()->where('public_id', $id)->first();

        if ($delivery === null) {
            $this->components->error((string) trans('webhooks::commands.delivery_not_found'));

            return self::FAILURE;
        }

        $webhooks->outbound()->redeliver($delivery);
        $this->components->info((string) trans('webhooks::commands.redelivered'));

        return self::SUCCESS;
    }
}
