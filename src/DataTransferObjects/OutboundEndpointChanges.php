<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * What to change on an outbound endpoint; null = unchanged. `$clear` names nullable fields to
 * reset (name, description, headers, rate_limit_per_minute, scheme_options).
 */
final readonly class OutboundEndpointChanges
{
    /**
     * @param  list<string>|null  $events
     * @param  array<string, string>|null  $headers
     * @param  list<string>  $clear
     */
    public function __construct(
        public ?string $url = null,
        public ?array $events = null,
        public ?string $name = null,
        public ?array $headers = null,
        public ?string $scheme = null,
        public ?OutboundEndpointOptions $options = null,
        public array $clear = [],
    ) {}
}
