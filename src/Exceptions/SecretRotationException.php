<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

final class SecretRotationException extends WebhooksException
{
    public static function graceTooLong(int $maxSeconds): self
    {
        return new self(self::translated('grace_too_long', ['seconds' => $maxSeconds]));
    }

    public static function graceInThePast(): self
    {
        return new self(self::translated('grace_in_the_past'));
    }
}
