<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Webhooks\Contracts\PayloadSanitizer;
use RoundlyConsulting\Webhooks\DataTransferObjects\SanitizeContext;

/**
 * The default sanitizer: replaces the value of every key named in `sanitizer.redact_keys`
 * (case-insensitive, at any depth up to 64) with `sanitizer.replacement`.
 */
final readonly class KeyRedactingSanitizer implements PayloadSanitizer
{
    private const int MAX_DEPTH = 64;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function sanitize(array $payload, SanitizeContext $context): array
    {
        return self::redact($payload, WebhooksConfig::redactKeys(), WebhooksConfig::redactReplacement(), 0);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function redact(array $payload, array $keys, string $replacement, int $depth): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $payload[$key] = $replacement;
            } elseif (is_array($value)) {
                $payload[$key] = $depth >= self::MAX_DEPTH ? $replacement : self::redact($value, $keys, $replacement, $depth + 1);
            }
        }

        return $payload;
    }
}
