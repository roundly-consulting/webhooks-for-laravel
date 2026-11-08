<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped;

use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;

/**
 * A host subclass named in the package's model config.
 */
final class CustomInboundEndpoint extends InboundEndpoint
{
    use CountsCreations;
}
