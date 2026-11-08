<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonImmutable;
use JsonSerializable;
use SensitiveParameter;

/**
 * The new secret after a rotation (shown once) and when the old one stops working.
 */
final readonly class RotatedSecret implements JsonSerializable
{
    public function __construct(
        #[SensitiveParameter] public string $secret,
        public CarbonImmutable $graceEndsAt,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]', 'graceEndsAt' => $this->graceEndsAt->toIso8601ZuluString()];
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
