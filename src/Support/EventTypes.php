<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;

/**
 * Event type names (`invoice.paid`) and subscriptions (exact, `invoice.*`, `*`).
 *
 * @internal
 */
final class EventTypes
{
    public const int MAX_LENGTH = 100;

    public static function valid(string $type): bool
    {
        return strlen($type) <= self::MAX_LENGTH && preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/D', $type) === 1;
    }

    /**
     * @throws InvalidEventTypeException
     */
    public static function assertSendable(string $type): void
    {
        if (! self::valid($type)) {
            throw InvalidEventTypeException::malformed($type);
        }

        $known = WebhooksConfig::knownEventTypes();

        if ($known !== [] && ! in_array($type, $known, true)) {
            throw InvalidEventTypeException::unknown($type);
        }
    }

    /**
     * A subscription list: 1–100 entries, each `*`, a type, or a type prefix + `.*`.
     *
     * @param  array<array-key, mixed>  $subscriptions
     * @return list<string>
     *
     * @throws InvalidEndpointException
     */
    public static function subscriptions(array $subscriptions): array
    {
        $list = [];

        foreach ($subscriptions as $entry) {
            if (! is_string($entry)) {
                throw InvalidEndpointException::eventTypes();
            }

            $prefix = str_ends_with($entry, '.*') ? substr($entry, 0, -2) : $entry;

            if ($entry !== '*' && ! self::valid($prefix)) {
                throw InvalidEndpointException::eventTypes();
            }

            $list[] = $entry;
        }

        $list = array_values(array_unique($list));

        if ($list === [] || count($list) > 100) {
            throw InvalidEndpointException::eventTypes();
        }

        return $list;
    }

    /**
     * @param  list<string>  $subscriptions
     */
    public static function matches(array $subscriptions, string $type): bool
    {
        foreach ($subscriptions as $entry) {
            if ($entry === '*' || $entry === $type || (str_ends_with($entry, '.*') && str_starts_with($type, substr($entry, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
}
