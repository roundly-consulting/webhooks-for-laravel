<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Enable an endpoint again — an outbound one after an auto-disable, say (its failure count
 * restarts). Then `webhooks:redeliver --endpoint=` resends what failed.
 */
final class EnableCommand extends Command
{
    protected $signature = 'webhooks:enable {direction : inbound or outbound} {endpoint : The endpoint id}';

    protected $description = 'Enable a webhook endpoint';

    public function handle(WebhooksManager $webhooks): int
    {
        $direction = $this->argument('direction');
        $id = (string) $this->argument('endpoint');

        if (! in_array($direction, ['inbound', 'outbound'], true)) {
            $this->components->error((string) trans('webhooks::commands.invalid_direction'));

            return self::INVALID;
        }

        try {
            $direction === 'inbound'
                ? $webhooks->inbound()->enable($webhooks->inbound()->find($id))
                : $webhooks->outbound()->enable($webhooks->outbound()->find($id));
        } catch (EndpointNotFoundException) {
            $this->components->error((string) trans('webhooks::commands.endpoint_not_found'));

            return self::FAILURE;
        }

        $this->components->info((string) trans('webhooks::commands.enabled'));

        return self::SUCCESS;
    }
}
