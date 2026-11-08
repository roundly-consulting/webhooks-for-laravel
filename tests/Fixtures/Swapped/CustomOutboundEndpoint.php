<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped;

use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * A host subclass named in the package's model config.
 */
final class CustomOutboundEndpoint extends OutboundEndpoint
{
    use CountsCreations;
}
