<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Webhooks\Actions\ExampleWebhooksAction;
use RoundlyConsulting\Webhooks\DataTransferObjects\ExampleWebhooksData;

/**
 * The package's public API: the root behind the Webhooks facade, injectable by its
 * own class-string. Bound as a singleton in WebhooksServiceProvider::register().
 *
 * Follows the `laravel-package-developer` skill section
 * "Public API: Actions → Manager → Facade (REQUIRED)":
 *
 *  - Every method is thin — resolve one action through the container and call `execute()`.
 *    Never `new` an action, so host container overrides and the fake both apply.
 *  - Flat methods for the core verbs; a small `final readonly` sub-accessor per sub-area once
 *    there are two or more; `for($model)` for a model-scoped handle.
 *  - Every host-facing action (no `@internal`) must be reachable from here.
 *  - Model traits and model methods call this manager (`app(WebhooksManager::class)`),
 *    never an action — so the fake sees them too.
 *
 * Not final on purpose: WebhooksFake extends it, so code that constructor-injects this
 * class still type-checks under `Webhooks::fake()`. The arch test exempts it from
 * `finalByDefault` by name.
 *
 * Exempt shapes — a pure trait, a per-request static builder, package-author or dev-only
 * tooling — have no host-facing stateful behaviour: delete this manager, the facade, the fake,
 * the example action and FacadeTest, and say why in one README line.
 */
class WebhooksManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * REPLACE ME — rename after the real verb the action performs.
     */
    public function example(ExampleWebhooksData $data): string
    {
        return $this->container->make(ExampleWebhooksAction::class)->execute($data);
    }
}
