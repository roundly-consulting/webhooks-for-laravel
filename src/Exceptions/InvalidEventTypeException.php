<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * An event type that is not `a.b_c` style (≤ 100 characters), or not one of the configured types.
 */
final class InvalidEventTypeException extends WebhooksException
{
    public static function malformed(string $type): self
    {
        return new self(self::translated('event_type_malformed', ['type' => mb_strimwidth($type, 0, 100, '…')]));
    }

    public static function unknown(string $type): self
    {
        return new self(self::translated('event_type_unknown', ['type' => $type]));
    }
}
