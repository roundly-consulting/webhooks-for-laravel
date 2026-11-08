<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\Jitter\Randomizer;
use RoundlyConsulting\SafeFetch\SafeFetchServiceProvider;
use RoundlyConsulting\Sentinel\SentinelServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers\BillingWebhookHandler;
use RoundlyConsulting\Webhooks\Tests\Support\FixedRandomizer;
use RoundlyConsulting\Webhooks\WebhooksServiceProvider;

/**
 * The package and its hard dependencies, a fixture host (teams that own endpoints, a handler
 * that counts its runs), and a deterministic jitter.
 */
abstract class TestCase extends PackageTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            SentinelServiceProvider::class,
            HttpClientRateLimitsServiceProvider::class,
            SafeFetchServiceProvider::class,
            WebhooksServiceProvider::class,
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            SentinelServiceProvider::class,
            WebhooksServiceProvider::class,
            __DIR__.'/Fixtures/migrations',
        ];
    }

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://app.test',
            'queue.default' => 'sync',
            'webhooks.handlers' => ['billing' => BillingWebhookHandler::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app?->instance(Randomizer::class, new FixedRandomizer(0));
    }
}
