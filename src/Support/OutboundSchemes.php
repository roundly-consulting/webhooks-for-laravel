<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Contracts\SignsOutboundWebhooks;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;

/**
 * The scheme an outbound endpoint may use: registered, and able to sign — a `sign()`
 * (`SignsOutboundWebhooks`) or RFC 9421, whose sentinel key may be configured later.
 *
 * @internal
 */
final class OutboundSchemes
{
    /**
     * @throws InvalidEndpointException|UnknownSchemeException
     */
    public static function signing(SchemeRegistry $schemes, string $name): string
    {
        if (! $schemes->has($name)) {
            throw UnknownSchemeException::named($name);
        }

        $scheme = $schemes->resolve($name);

        if (! $scheme instanceof SignsOutboundWebhooks && ! $scheme instanceof Rfc9421Scheme) {
            throw InvalidEndpointException::schemeCannotSign($name);
        }

        return $name;
    }
}
