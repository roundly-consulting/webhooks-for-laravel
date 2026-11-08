<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;

/**
 * Validates per-endpoint inbound overrides into column values.
 *
 * @internal
 */
final class InboundOptions
{
    /**
     * @return array<string, mixed>
     *
     * @throws InvalidEndpointException
     */
    public static function columns(InboundEndpointOptions $options): array
    {
        $columns = [];

        if ($options->rateLimitPerMinute !== null) {
            $columns['rate_limit_per_minute'] = self::between($options->rateLimitPerMinute, 1, 1000000, 'rate_limit_per_minute');
        }

        if ($options->maxPayloadBytes !== null) {
            $columns['max_payload_bytes'] = self::between($options->maxPayloadBytes, 1, WebhooksConfig::inboundPayloadCeiling(), 'max_payload_bytes');
        }

        if ($options->contentTypes !== null) {
            $columns['allowed_content_types'] = self::contentTypes($options->contentTypes);
        }

        if ($options->toleranceSeconds !== null) {
            $columns['tolerance_seconds'] = self::between($options->toleranceSeconds, 1, 3600, 'tolerance_seconds');
        }

        if ($options->schemeOptions !== null) {
            $columns['scheme_options'] = self::schemeOptions($options->schemeOptions);
        }

        return $columns;
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<string, string>
     *
     * @throws InvalidEndpointException
     */
    public static function schemeOptions(array $options): array
    {
        $valid = [];

        foreach ($options as $name => $value) {
            if (! is_string($name) || preg_match('/^[a-z0-9_]{1,64}$/D', $name) !== 1 || ! is_string($value) || strlen($value) > 255) {
                throw InvalidEndpointException::option('scheme_options');
            }

            $valid[$name] = $value;
        }

        return $valid;
    }

    private static function between(int $value, int $min, int $max, string $option): int
    {
        if ($value < $min || $value > $max) {
            throw InvalidEndpointException::option($option);
        }

        return $value;
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private static function contentTypes(array $types): array
    {
        $valid = [];

        foreach ($types as $type) {
            if (preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+*-]+$#iD', $type) !== 1) {
                throw InvalidEndpointException::option('content_types');
            }

            $valid[] = strtolower($type);
        }

        if ($valid === []) {
            throw InvalidEndpointException::option('content_types');
        }

        return array_values(array_unique($valid));
    }
}
