<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * A receiver's answer as stored: at most `outbound.max_response_bytes` of valid UTF-8 (null when
 * empty), and whether the answer was longer.
 *
 * @internal
 */
final readonly class BodyExcerpt
{
    public function __construct(
        public ?string $text,
        public bool $truncated,
    ) {}
}
