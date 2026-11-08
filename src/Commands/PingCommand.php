<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Send a `webhook.ping` test event to an outbound endpoint.
 */
final class PingCommand extends Command
{
    protected $signature = 'webhooks:ping {endpoint : The outbound endpoint id}';

    protected $description = 'Send a test event to an outbound webhook endpoint';

    public function handle(WebhooksManager $webhooks): int
    {
        try {
            $delivery = $webhooks->outbound()->ping($webhooks->outbound()->find((string) $this->argument('endpoint')));
        } catch (EndpointNotFoundException) {
            $this->components->error((string) trans('webhooks::commands.endpoint_not_found'));

            return self::FAILURE;
        } catch (EndpointNotAcceptingException) {
            $this->components->error((string) trans('webhooks::commands.endpoint_disabled'));

            return self::FAILURE;
        }

        $this->components->info((string) trans('webhooks::commands.pinged', ['id' => $delivery->webhookId()]));

        return self::SUCCESS;
    }
}
