<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Webhooks\DataTransferObjects\ExampleWebhooksData;
use RoundlyConsulting\Webhooks\Testing\WebhooksFake;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Keep one `@method static` line per public manager method — FacadeTest's
 * `toDocumentItsRoot()` fails on a missing, stale or miscounted line.
 *
 * @method static string example(ExampleWebhooksData $data)
 * @method static void assertExampleCalled(\Closure|null $callback = null)
 * @method static void assertNothingCalled()
 *
 * @see WebhooksManager
 */
final class Webhooks extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected WebhooksManager is faked too.
     */
    public static function fake(): WebhooksFake
    {
        $fake = app(WebhooksFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return WebhooksManager::class;
    }
}
