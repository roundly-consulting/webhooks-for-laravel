<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Apply retention: clear old payloads and bodies, delete old delivery rows, clear expired
 * rotated-out secrets, remove deleted endpoints nothing points at.
 */
final class PruneCommand extends Command
{
    protected $signature = 'webhooks:prune {--dry-run : Count what would be pruned, change nothing}';

    protected $description = 'Prune expired webhook payloads, deliveries and secrets';

    public function handle(WebhooksManager $webhooks): int
    {
        $result = $webhooks->prune(new PruneOptions(dryRun: (bool) $this->option('dry-run')));

        $this->components->info((string) trans($result->dryRun ? 'webhooks::commands.pruned_dry' : 'webhooks::commands.pruned', ['count' => $result->total()]));
        $this->components->twoColumnDetail('inbound payloads', (string) $result->inboundPayloads);
        $this->components->twoColumnDetail('outbound bodies', (string) $result->outboundBodies);
        $this->components->twoColumnDetail('attempt bodies', (string) $result->attemptBodies);
        $this->components->twoColumnDetail('inbound deliveries', (string) $result->inboundDeliveries);
        $this->components->twoColumnDetail('outbound deliveries', (string) $result->outboundDeliveries);
        $this->components->twoColumnDetail('attempts', (string) $result->attempts);
        $this->components->twoColumnDetail('expired secrets', (string) $result->expiredSecrets);
        $this->components->twoColumnDetail('deleted endpoints', (string) $result->endpoints);

        return self::SUCCESS;
    }
}
