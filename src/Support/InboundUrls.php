<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

/**
 * The URL a sender posts to: `inbound.route.url_base` + `/` + key when set (an app behind a
 * proxy), else the named route.
 *
 * @internal
 */
final class InboundUrls
{
    public const string ROUTE = 'webhooks.inbound';

    public static function for(string $key): string
    {
        $base = WebhooksConfig::inboundUrlBase();

        if ($base !== null) {
            return $base.'/'.$key;
        }

        if (app('router')->has(self::ROUTE)) {
            return route(self::ROUTE, ['key' => $key]);
        }

        return url(WebhooksConfig::routePrefix().'/'.$key);
    }
}
