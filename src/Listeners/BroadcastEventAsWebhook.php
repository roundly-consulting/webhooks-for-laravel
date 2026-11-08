<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Listeners;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Webhooks\Contracts\BroadcastsAsWebhook;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Broadcasts every dispatched `BroadcastsAsWebhook` event — through the manager, so
 * `Webhooks::fake()` records it like any other broadcast.
 *
 * @internal registered by the service provider
 */
final readonly class BroadcastEventAsWebhook
{
    public function __construct(private Container $container) {}

    public function handle(BroadcastsAsWebhook $event): void
    {
        $this->container->make(WebhooksManager::class)->broadcast($event->webhookType(), $event->webhookPayload(), $event->webhookOwner());
    }
}
