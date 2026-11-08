<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Support;

use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomInboundDelivery;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomInboundEndpoint;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomOutboundAttempt;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomOutboundDelivery;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomOutboundEndpoint;
use RoundlyConsulting\Webhooks\Tests\TestCase;

/**
 * Every package model swapped for a host subclass before boot.
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return parent::configBeforeBoot() + [
            'webhooks.models.inbound_endpoint' => CustomInboundEndpoint::class,
            'webhooks.models.inbound_delivery' => CustomInboundDelivery::class,
            'webhooks.models.outbound_endpoint' => CustomOutboundEndpoint::class,
            'webhooks.models.outbound_delivery' => CustomOutboundDelivery::class,
            'webhooks.models.outbound_attempt' => CustomOutboundAttempt::class,
        ];
    }
}
