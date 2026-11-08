<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * An endpoint value the package refuses: a URL, a header, a subscription, a secret, an option.
 */
final class InvalidEndpointException extends WebhooksException
{
    public static function url(): self
    {
        return new self(self::translated('invalid_url'));
    }

    public static function header(string $why): self
    {
        return new self(self::translated('invalid_header', ['why' => $why]));
    }

    public static function eventTypes(): self
    {
        return new self(self::translated('invalid_event_types'));
    }

    public static function secret(): self
    {
        return new self(self::translated('invalid_secret'));
    }

    public static function option(string $option): self
    {
        return new self(self::translated('invalid_option', ['option' => $option]));
    }
}
