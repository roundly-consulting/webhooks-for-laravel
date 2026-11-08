<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

final class PayloadTooLargeException extends WebhooksException
{
    public static function make(int $bytes, int $limit): self
    {
        return new self(self::translated('payload_too_large', ['bytes' => $bytes, 'limit' => $limit]));
    }
}
