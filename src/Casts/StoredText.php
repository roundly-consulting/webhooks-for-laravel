<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * A stored text body (an outbound request body, the exact bytes sent): encrypted at rest while
 * `webhooks.payloads.encrypt` is on, marked per row so the setting can change.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class StoredText implements CastsAttributes
{
    private const string ENCRYPTED = 'enc:';

    private const string PLAIN = 'raw:';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::read($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? self::write($value) : null;
    }

    public static function write(string $text): string
    {
        return WebhooksConfig::encryptPayloads()
            ? self::ENCRYPTED.Crypt::encryptString($text)
            : self::PLAIN.$text;
    }

    public static function read(mixed $stored): ?string
    {
        if (! is_string($stored)) {
            return null;
        }

        return match (true) {
            str_starts_with($stored, self::ENCRYPTED) => Crypt::decryptString(substr($stored, strlen(self::ENCRYPTED))),
            str_starts_with($stored, self::PLAIN) => substr($stored, strlen(self::PLAIN)),
            default => null,
        };
    }
}
