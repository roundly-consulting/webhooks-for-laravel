<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

final class UnknownHandlerException extends WebhooksException
{
    public static function named(string $alias): self
    {
        return new self(self::translated('unknown_handler', ['alias' => mb_strimwidth($alias, 0, 100, '…')]));
    }

    public static function notAHandler(string $alias): self
    {
        return new self(self::translated('not_a_handler', ['alias' => $alias]));
    }
}
