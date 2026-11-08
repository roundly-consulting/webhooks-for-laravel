<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;

/**
 * The configured model classes — the one place the package names them. A replacement must
 * extend the packaged model.
 *
 * @internal
 */
final class ModelSeams
{
    /**
     * @return class-string<InboundEndpoint>
     */
    public static function inboundEndpoint(): string
    {
        return ModelResolver::for('webhooks.models.inbound_endpoint', InboundEndpoint::class);
    }

    /**
     * @return class-string<InboundDelivery>
     */
    public static function inboundDelivery(): string
    {
        return ModelResolver::for('webhooks.models.inbound_delivery', InboundDelivery::class);
    }

    /**
     * @return class-string<OutboundEndpoint>
     */
    public static function outboundEndpoint(): string
    {
        return ModelResolver::for('webhooks.models.outbound_endpoint', OutboundEndpoint::class);
    }

    /**
     * @return class-string<OutboundDelivery>
     */
    public static function outboundDelivery(): string
    {
        return ModelResolver::for('webhooks.models.outbound_delivery', OutboundDelivery::class);
    }

    /**
     * @return class-string<OutboundAttempt>
     */
    public static function outboundAttempt(): string
    {
        return ModelResolver::for('webhooks.models.outbound_attempt', OutboundAttempt::class);
    }
}
