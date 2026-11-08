<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Contracts;

use RoundlyConsulting\Webhooks\DataTransferObjects\SanitizeContext;

/**
 * Strips or redacts fields before a payload is stored, handed to a handler or rendered.
 */
interface PayloadSanitizer
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function sanitize(array $payload, SanitizeContext $context): array;
}
