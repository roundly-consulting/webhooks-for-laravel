<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * No such endpoint — or one that belongs to another owner (a scoped handle is a boundary).
 */
final class EndpointNotFoundException extends WebhooksException
{
    public static function make(): self
    {
        return new self(self::translated('endpoint_not_found'));
    }
}
