<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * An outbound endpoint's scheme cannot sign (unknown, verify-only, or an RFC 9421 endpoint with
 * no key id). The delivery fails without a request.
 */
final class SigningUnavailableException extends WebhooksException
{
    public static function make(): self
    {
        return new self(self::translated('signing_unavailable'));
    }
}
