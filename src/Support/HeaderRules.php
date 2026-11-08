<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;

/**
 * Custom outbound headers: RFC 9110 token names, no CR/LF/NUL in values, ≤ 20 headers, ≤ 8 KB.
 * Framing, signature and proxy headers are never settable; `host` and `authorization` only when
 * `webhooks.outbound.headers.allowed_overrides` lists them.
 *
 * @internal
 */
final class HeaderRules
{
    private const array ALWAYS_RESERVED = [
        'content-length', 'content-type', 'transfer-encoding', 'connection', 'te', 'upgrade', 'cookie',
        'content-digest', 'keep-alive', 'expect', 'trailer',
    ];

    private const array OVERRIDABLE = ['host', 'authorization'];

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string> lower-cased names
     *
     * @throws InvalidEndpointException
     */
    public static function validate(array $headers): array
    {
        if (count($headers) > 20) {
            throw InvalidEndpointException::header('too_many');
        }

        $valid = [];
        $size = 0;
        $overrides = WebhooksConfig::allowedHeaderOverrides();

        foreach ($headers as $name => $value) {
            if (! is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}$/D', $name) !== 1) {
                throw InvalidEndpointException::header('name');
            }

            $lower = strtolower($name);

            if (! is_string($value) || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw InvalidEndpointException::header('value');
            }

            if (self::reserved($lower) && ! (in_array($lower, self::OVERRIDABLE, true) && in_array($lower, $overrides, true))) {
                throw InvalidEndpointException::header('reserved');
            }

            $size += strlen($name) + strlen($value);
            $valid[$lower] = $value;
        }

        if ($size > 8192) {
            throw InvalidEndpointException::header('too_large');
        }

        return $valid;
    }

    public static function reserved(string $name): bool
    {
        return in_array($name, self::ALWAYS_RESERVED, true)
            || in_array($name, self::OVERRIDABLE, true)
            || str_starts_with($name, 'webhook-')
            || str_starts_with($name, 'signature')
            || str_starts_with($name, 'proxy-');
    }

    /**
     * Headers that carry a credential or a signature — never stored, never logged.
     */
    public static function secretBearing(string $name): bool
    {
        return in_array($name, ['authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'webhook-signature'], true)
            || str_starts_with($name, 'signature')
            || str_contains($name, 'token')
            || str_contains($name, 'secret')
            || str_contains($name, 'signature')
            || str_contains($name, 'api-key');
    }
}
