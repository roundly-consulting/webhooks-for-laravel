<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Webhooks\DataTransferObjects\RotatedSecret;
use RoundlyConsulting\Webhooks\Enums\Direction;
use RoundlyConsulting\Webhooks\Events\EndpointSecretRotated;
use RoundlyConsulting\Webhooks\Exceptions\SecretRotationException;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\SecretRotation;

/**
 * Rotate an outbound endpoint's signing secret. Until the grace ends every delivery carries a
 * signature under each secret (two `v1,` entries), so the receiver can switch at its own pace.
 */
final readonly class RotateOutboundSecretAction
{
    public function __construct(private Dispatcher $events) {}

    /**
     * @throws SecretRotationException
     */
    public function execute(OutboundEndpoint $endpoint, ?CarbonInterface $grace = null): RotatedSecret
    {
        $rotated = SecretRotation::rotate($endpoint, $grace);

        $this->events->dispatch(new EndpointSecretRotated($endpoint, Direction::Outbound, $rotated->graceEndsAt));

        return $rotated;
    }
}
