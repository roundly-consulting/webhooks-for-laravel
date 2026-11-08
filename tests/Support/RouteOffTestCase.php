<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Support;

use RoundlyConsulting\Webhooks\Tests\TestCase;

/**
 * A host that mounts its own inbound route: the package's is switched off before boot.
 */
abstract class RouteOffTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return ['webhooks.inbound.route.enabled' => 'off'] + parent::configBeforeBoot();
    }
}
