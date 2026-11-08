<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * A signed inbound request built for a test: where to post it, its headers and its exact body.
 */
final readonly class SignedRequestData
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $uri,
        public array $headers,
        public string $body,
    ) {}
}
