<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

/**
 * A receiver's answer as storable text: at most the configured bytes, valid UTF-8 (invalid
 * sequences replaced), no NUL bytes.
 *
 * @internal
 */
final class BodyReader
{
    public static function excerpt(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $body = substr($body, 0, WebhooksConfig::maxResponseBytes());
        $text = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        return str_replace("\0", "\u{FFFD}", $text);
    }
}
