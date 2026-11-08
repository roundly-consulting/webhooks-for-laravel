<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * `send()` to an endpoint that is disabled or not subscribed to the event type.
 */
final class EndpointNotAcceptingException extends WebhooksException
{
    private string $reason = 'disabled';

    public static function disabled(): self
    {
        $exception = new self(self::translated('endpoint_disabled'));
        $exception->reason = 'disabled';

        return $exception;
    }

    public static function notSubscribed(string $eventType): self
    {
        $exception = new self(self::translated('endpoint_not_subscribed', ['type' => $eventType]));
        $exception->reason = 'not_subscribed';

        return $exception;
    }

    /**
     * `disabled` or `not_subscribed`.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
