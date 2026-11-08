<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;

/**
 * Validates per-endpoint outbound settings into column values.
 *
 * @internal
 */
final class OutboundOptions
{
    /**
     * @return array<string, mixed>
     *
     * @throws InvalidEndpointException
     */
    public static function columns(OutboundEndpointOptions $options): array
    {
        $columns = [];

        if ($options->rateLimitPerMinute !== null) {
            $columns['rate_limit_per_minute'] = $options->rateLimitPerMinute >= 1 && $options->rateLimitPerMinute <= 1000000
                ? $options->rateLimitPerMinute
                : throw InvalidEndpointException::option('rate_limit_per_minute');
        }

        if ($options->schemeOptions !== null) {
            $columns['scheme_options'] = InboundOptions::schemeOptions($options->schemeOptions);
        }

        if ($options->description !== null) {
            $columns['description'] = mb_strlen($options->description) <= 1000
                ? $options->description
                : throw InvalidEndpointException::option('description');
        }

        return $columns;
    }
}
