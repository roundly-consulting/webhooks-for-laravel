<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use JsonSerializable;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use SensitiveParameter;

/**
 * A new inbound endpoint with its URL and its secret — the only time the secret is readable.
 * Dumping or JSON-encoding this object never shows it.
 */
final readonly class CreatedInboundEndpoint implements JsonSerializable
{
    public function __construct(
        public InboundEndpoint $endpoint,
        public string $url,
        #[SensitiveParameter] public string $secret,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint->getKey(), 'url' => $this->url, 'secret' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
