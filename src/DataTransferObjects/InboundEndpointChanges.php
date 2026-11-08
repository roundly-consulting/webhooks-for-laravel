<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * What to change on an inbound endpoint. Only the fields named in `$fields` (or given non-null)
 * change; pass `clear: ['handler']` style names to set a nullable field back to null.
 */
final readonly class InboundEndpointChanges
{
    /**
     * @param  list<string>  $clear  fields to reset to null (name, handler, rate_limit_per_minute, max_payload_bytes, allowed_content_types, tolerance_seconds, scheme_options)
     */
    public function __construct(
        public ?string $name = null,
        public ?string $handler = null,
        public ?string $scheme = null,
        public ?InboundEndpointOptions $options = null,
        public array $clear = [],
    ) {}
}
