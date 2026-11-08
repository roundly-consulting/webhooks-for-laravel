<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;
use RoundlyConsulting\HttpClientRateLimits\Jitter\RandomRandomizer;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Commands\DoctorCommand;
use RoundlyConsulting\Webhooks\Commands\EnableCommand;
use RoundlyConsulting\Webhooks\Commands\EndpointsCommand;
use RoundlyConsulting\Webhooks\Commands\InstallCommand;
use RoundlyConsulting\Webhooks\Commands\MakeSchemeCommand;
use RoundlyConsulting\Webhooks\Commands\PingCommand;
use RoundlyConsulting\Webhooks\Commands\PruneCommand;
use RoundlyConsulting\Webhooks\Commands\RedeliverCommand;
use RoundlyConsulting\Webhooks\Commands\ReplayCommand;
use RoundlyConsulting\Webhooks\Commands\RetryCommand;
use RoundlyConsulting\Webhooks\Commands\RotateSecretCommand;
use RoundlyConsulting\Webhooks\Contracts\BroadcastsAsWebhook;
use RoundlyConsulting\Webhooks\Contracts\PayloadSanitizer;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;
use RoundlyConsulting\Webhooks\Listeners\BroadcastEventAsWebhook;
use RoundlyConsulting\Webhooks\Support\InboundRoute;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

final class WebhooksServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('webhooks')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasRoutes('webhooks.php', 'webhooks.inbound.route.enabled', true)
            ->hasCommands([
                InstallCommand::class,
                DoctorCommand::class,
                PruneCommand::class,
                RetryCommand::class,
                RotateSecretCommand::class,
                RedeliverCommand::class,
                ReplayCommand::class,
                EndpointsCommand::class,
                EnableCommand::class,
                PingCommand::class,
                MakeSchemeCommand::class,
            ])
            ->contributesToAbout(static fn (): array => [
                'Route prefix' => WebhooksConfig::routeEnabled() ? '/'.WebhooksConfig::routePrefix().'/{key}' : 'OFF',
                'Queue' => (WebhooksConfig::queueConnection() ?? 'default').' (in: '.(WebhooksConfig::inboundQueue() ?? 'default').', out: '.(WebhooksConfig::outboundQueue() ?? 'default').')',
                'Schemes' => implode(', ', array_keys(WebhooksConfig::schemes())),
                'Handlers' => (string) count(WebhooksConfig::handlers()),
                'SSRF guard' => WebhooksConfig::ssrfEnabled() ? 'ON' : 'OFF',
                'Outbound schemes' => WebhooksConfig::allowHttp() ? 'http, https' : 'https only',
                'Retries' => WebhooksConfig::maxAttempts().' attempts, every '.WebhooksConfig::retryBase().'s × '.WebhooksConfig::retryMultiplier().' (cap '.WebhooksConfig::retryCap().'s)',
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->scoped(WebhooksManager::class);
        // Process-wide: they hold only definitions, and a queue worker (Octane: every request)
        // forgets scoped instances before each job — a boot()-time registration must outlive that.
        $this->app->singleton(SchemeRegistry::class);
        $this->app->singleton(HandlerRegistry::class);
        $this->app->bindIf(Randomizer::class, RandomRandomizer::class);
        $this->app->bind(PayloadSanitizer::class, static function (Application $app): PayloadSanitizer {
            $sanitizer = $app->make(WebhooksConfig::sanitizer());

            return $sanitizer instanceof PayloadSanitizer
                ? $sanitizer
                : throw InvalidWebhooksConfigurationException::notAnImplementation('webhooks.sanitizer.class', PayloadSanitizer::class);
        });
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerBlueprintMacros();
        $this->app->make(Dispatcher::class)->listen(BroadcastsAsWebhook::class, BroadcastEventAsWebhook::class);

        if (WebhooksConfig::routeEnabled()) {
            InboundRoute::skipBodyDecoding(WebhooksConfig::routePrefix());
        }

        // Route::webhooks('hooks', ['throttle:webhooks']) — a host's own inbound route.
        Router::macro('webhooks', function (string $prefix = 'webhooks', array $middleware = []): Route {
            /** @var Router $this */
            return InboundRoute::mount($this, $prefix, array_values(array_filter($middleware, is_string(...))));
        });

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (! WebhooksConfig::scheduleEnabled()) {
                return;
            }

            $ready = static fn (): bool => Schema::hasTable(WebhooksConfig::outboundDeliveriesTable());

            self::every($schedule->command('webhooks:retry'), WebhooksConfig::retryFrequency())->withoutOverlapping()->onOneServer()->when($ready);
            self::every($schedule->command('webhooks:prune'), WebhooksConfig::pruneFrequency())->withoutOverlapping()->onOneServer()->when($ready);
        });
    }

    private static function every(Event $event, string $frequency): Event
    {
        return match ($frequency) {
            'everyTwoMinutes' => $event->everyTwoMinutes(),
            'everyFiveMinutes' => $event->everyFiveMinutes(),
            'hourly' => $event->hourly(),
            'weekly' => $event->weekly(),
            'daily' => $event->daily(),
            default => $event->everyMinute(),
        };
    }
}
