<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks;

use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class WebhooksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('webhooks')
            ->hasConfigFile()
            // Read every setting through the toolkit's strict Config readers — boolean(),
            // integer(), oneOf() / enum(), requireString() — never a cast or a truthiness check:
            // env() leaves `off`/`no` as non-empty (truthy) strings, `(int) 'five'` is 0, and a
            // typo must throw naming the key instead of falling back to a default. A blank value
            // (a host's `KEY=`) is not set: the readers return the default, exactly as if absent.
            ->contributesToAbout(static fn (): array => [
                'Enabled' => Config::boolean('webhooks.enabled', true) ? 'YES' : 'NO',
                'Timeout' => Config::integer('webhooks.timeout', 30, min: 1, max: 300).'s',
                'Mode' => Config::oneOf('webhooks.mode', ['sync', 'queue'], 'sync'),
            ]);

        // Grow this as the package grows:
        //   ->hasMigrations()
        //   ->hasCommands([SomeCommand::class])
        //   ->hasViews() / ->hasTranslations()
        //   ->hasRoutes('webhooks.php')
        // See RoundlyConsulting\PackageToolkit\Package for the full fluent API.
        //
        // The Webhooks facade's global alias is declared in composer.json
        // (extra.laravel.aliases), not here — the skill's facade rule, and package discovery
        // registers it without booting any code.
    }

    public function register(): void
    {
        parent::register();

        // Container bindings (bind/singleton/scoped) go here — never in boot().
        $this->app->singleton(WebhooksManager::class);
    }
}
