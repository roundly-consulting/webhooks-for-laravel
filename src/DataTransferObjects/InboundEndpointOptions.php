<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * Per-endpoint overrides of the inbound settings; null = the configured value.
 */
final readonly class InboundEndpointOptions
{
    /**
     * @param  list<string>|null  $contentTypes
     * @param  array<string, string>|null  $schemeOptions
     */
    public function __construct(
        public ?int $rateLimitPerMinute = null,
        public ?int $maxPayloadBytes = null,
        public ?array $contentTypes = null,
        public ?int $toleranceSeconds = null,
        public ?array $schemeOptions = null,
    ) {}
}
