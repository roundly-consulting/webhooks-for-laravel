<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Schemes;

use RoundlyConsulting\Sentinel\Enums\Algorithm;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use SensitiveParameter;

/**
 * An endpoint's currently valid secrets (current, plus the previous one during a rotation
 * grace), handed to a scheme without ever exposing them: a scheme asks whether a MAC matches, or
 * for the MACs of a message. Every MAC runs through sentinel.
 */
final readonly class EndpointSecrets
{
    /**
     * @param  list<string>  $secrets  current first
     */
    public function __construct(
        #[SensitiveParameter] private array $secrets,
        private MacEngine $engine,
    ) {}

    /**
     * Which secret the raw MAC of `$message` matches, or null.
     */
    public function matches(string $message, #[SensitiveParameter] string $macBytes, SecretEncoding $encoding, Algorithm $algorithm = Algorithm::HmacSha256): ?SecretSlot
    {
        $index = $this->engine->matches($message, $macBytes, $this->secrets, $encoding, $algorithm);

        return match ($index) {
            null => null,
            0 => SecretSlot::Current,
            default => SecretSlot::Previous,
        };
    }

    /**
     * The raw MACs of `$message`, current secret first.
     *
     * @return list<string>
     */
    public function sign(string $message, SecretEncoding $encoding, Algorithm $algorithm = Algorithm::HmacSha256): array
    {
        return $this->engine->sign($message, $this->secrets, $encoding, $algorithm);
    }

    public function count(): int
    {
        return count($this->secrets);
    }

    /**
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['secrets' => count($this->secrets)];
    }
}
