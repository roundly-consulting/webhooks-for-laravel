<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * Per-endpoint outbound settings; null = the configured value.
 */
final readonly class OutboundEndpointOptions
{
    /**
     * @param  array<string, string>|null  $schemeOptions
     */
    public function __construct(
        public ?int $rateLimitPerMinute = null,
        public ?array $schemeOptions = null,
        public ?string $description = null,
    ) {}
}
