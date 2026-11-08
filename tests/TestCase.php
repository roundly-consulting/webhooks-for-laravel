<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Webhooks\WebhooksServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [WebhooksServiceProvider::class];
    }

    // No migrationSources() override — the base case defaults to []. Add one once the
    // package ships migrations: return [WebhooksServiceProvider::class] (never a
    // literal filename) once ->hasMigrations() is wired in the provider.
    //
    // With migrations, also keep the migrated schema (migrate once per worker, empty the
    // tables after each test) — forked racers must then end with SIGKILL:
    //
    //     protected function keepsMigratedSchema(): bool
    //     {
    //         return true;
    //     }
}
