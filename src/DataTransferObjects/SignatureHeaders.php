<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * The headers a scheme adds to an outbound request.
 */
final readonly class SignatureHeaders
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(public array $headers) {}
}
