<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Crypto\Codec\InvalidEncodingException;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use SensitiveParameter;

/**
 * Signing secrets: generated as `whsec_` + standard base64 of random bytes (Standard Webhooks
 * format, recognisable by secret scanners); imported ones are kept verbatim. How a stored string
 * becomes HMAC key bytes is the scheme's choice ({@see SecretEncoding}), never the import's.
 *
 * @internal
 */
final class Secrets
{
    public const string PREFIX = 'whsec_';

    public const int MAX_IMPORTED_BYTES = 1024;

    public static function generate(): string
    {
        return self::PREFIX.Crypto::base64Encode(Crypto::randomBytes(WebhooksConfig::secretBytes()));
    }

    /**
     * An imported secret, stored verbatim: 1–1 024 bytes, no control characters.
     *
     * @throws InvalidEndpointException
     */
    public static function imported(#[SensitiveParameter] string $secret): string
    {
        if ($secret === '' || strlen($secret) > self::MAX_IMPORTED_BYTES || preg_match('/[\x00-\x1f\x7f]/', $secret) === 1) {
            throw InvalidEndpointException::secret();
        }

        return $secret;
    }

    /**
     * The HMAC key bytes of a stored secret under an encoding. A Standard Webhooks secret that
     * does not decode cannot sign or verify anything: null.
     */
    public static function keyBytes(#[SensitiveParameter] string $secret, SecretEncoding $encoding): ?string
    {
        if ($encoding === SecretEncoding::Raw) {
            return $secret === '' ? null : $secret;
        }

        // Standard Webhooks: the bytes behind `whsec_`. A secret without the prefix (imported
        // from a sender that hands out plain strings) is its own bytes.
        if (! str_starts_with($secret, self::PREFIX)) {
            return $secret === '' ? null : $secret;
        }

        try {
            $bytes = Crypto::base64Decode(substr($secret, strlen(self::PREFIX)));
        } catch (InvalidEncodingException) {
            return null;
        }

        return $bytes === '' ? null : $bytes;
    }
}
