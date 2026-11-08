<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\RotatedSecret;
use RoundlyConsulting\Webhooks\Enums\Direction;
use RoundlyConsulting\Webhooks\Events\EndpointSecretRotated;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\SecretRotationException;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\SecretRotation;
use SensitiveParameter;

/**
 * Rotate an inbound endpoint's secret: the old one keeps verifying until the grace ends (default
 * `secrets.grace_seconds`; `grace: now()` = no overlap). Pass `secret:` to import the sender's
 * new secret instead of generating one.
 */
final readonly class RotateInboundSecretAction
{
    public function __construct(
        private Dispatcher $events,
        private SchemeRegistry $schemes,
    ) {}

    /**
     * @throws SecretRotationException|InvalidEndpointException
     */
    public function execute(InboundEndpoint $endpoint, ?CarbonInterface $grace = null, #[SensitiveParameter] ?string $secret = null): RotatedSecret
    {
        $rotated = SecretRotation::rotate($endpoint, $grace, $secret, $this->schemes->secretEncoding($endpoint->signature_scheme));

        $this->events->dispatch(new EndpointSecretRotated($endpoint, Direction::Inbound, $rotated->graceEndsAt));

        return $rotated;
    }
}
