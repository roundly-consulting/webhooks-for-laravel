<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;

/**
 * Publish the config, the webhooks migrations and sentinel's (the idempotency store), then say
 * what is left to do.
 */
final class InstallCommand extends Command
{
    protected $signature = 'webhooks:install';

    protected $description = 'Publish the webhooks config and migrations (with sentinel\'s)';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'webhooks-config']);
        $this->call('vendor:publish', ['--tag' => 'webhooks-migrations']);
        $this->call('vendor:publish', ['--tag' => 'sentinel-migrations']);

        $this->components->info((string) trans('webhooks::commands.installed'));
        $this->components->bulletList([
            (string) trans('webhooks::commands.next_migrate'),
            (string) trans('webhooks::commands.next_queue'),
            (string) trans('webhooks::commands.next_schedule'),
        ]);

        return self::SUCCESS;
    }
}
