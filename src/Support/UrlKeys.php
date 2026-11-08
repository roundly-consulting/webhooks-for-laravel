<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Crypto\Facades\Crypto;

/**
 * Inbound URL keys: 32 random alphanumeric characters (~190 bits), unguessable.
 *
 * @internal
 */
final class UrlKeys
{
    public const string PATTERN = '[A-Za-z0-9]{32}';

    public static function generate(): string
    {
        return Crypto::random()->alphanumeric(32);
    }

    public static function valid(string $key): bool
    {
        return preg_match('/^'.self::PATTERN.'$/D', $key) === 1;
    }
}
