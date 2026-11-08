<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use SensitiveParameter;

/**
 * An outbound endpoint URL as stored: canonical, and its host (kept in clear for listings).
 *
 * @internal
 */
final readonly class JudgedUrl
{
    public function __construct(
        #[SensitiveParameter] public string $url,
        public string $host,
    ) {}
}
