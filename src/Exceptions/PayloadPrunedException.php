<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * The stored payload was cleared by retention, so the delivery cannot be replayed or redelivered.
 */
final class PayloadPrunedException extends WebhooksException
{
    public static function make(): self
    {
        return new self(self::translated('payload_pruned'));
    }
}
