<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use JsonSerializable;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use SensitiveParameter;

/**
 * A new outbound endpoint and its signing secret — the only time the secret is readable. Hand
 * it to the receiver once.
 */
final readonly class CreatedOutboundEndpoint implements JsonSerializable
{
    public function __construct(
        public OutboundEndpoint $endpoint,
        #[SensitiveParameter] public string $secret,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint->getKey(), 'secret' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
