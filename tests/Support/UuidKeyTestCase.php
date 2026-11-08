<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Support;

use RoundlyConsulting\Webhooks\Tests\TestCase;

/**
 * The package's own keys and the owner morph on uuid columns.
 */
abstract class UuidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return ['webhooks.primary_key_type' => 'uuid', 'webhooks.key_type' => 'uuid'] + parent::configBeforeBoot();
    }
}
