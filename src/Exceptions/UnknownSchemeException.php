<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

final class UnknownSchemeException extends WebhooksException
{
    public static function named(string $name): self
    {
        return new self(self::translated('unknown_scheme', ['name' => mb_strimwidth($name, 0, 64, '…')]));
    }

    public static function notAScheme(string $name): self
    {
        return new self(self::translated('not_a_scheme', ['name' => $name]));
    }
}
