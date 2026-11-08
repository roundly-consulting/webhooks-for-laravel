<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped;

use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * A host subclass named in the package's model config.
 */
final class CustomOutboundDelivery extends OutboundDelivery
{
    use CountsCreations;
}
