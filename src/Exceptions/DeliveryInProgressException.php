<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * A delivery that is still queued, sending or processing cannot be replayed or redelivered yet.
 */
final class DeliveryInProgressException extends WebhooksException
{
    public static function make(): self
    {
        return new self(self::translated('delivery_in_progress'));
    }
}
