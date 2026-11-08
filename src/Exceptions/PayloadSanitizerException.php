<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

use Throwable;

/**
 * The configured sanitizer threw (or returned something other than an array). The delivery is
 * refused rather than stored unsanitized.
 */
final class PayloadSanitizerException extends WebhooksException
{
    public static function from(Throwable $previous): self
    {
        return new self(self::translated('sanitizer_failed'), 0, $previous);
    }
}
