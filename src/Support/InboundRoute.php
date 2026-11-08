<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use RoundlyConsulting\Webhooks\Http\Controllers\ReceiveWebhookController;

/**
 * Mounts the inbound pipeline: what `Route::webhooks()` does for a host that mounts its own
 * route — the `POST {prefix}/{key}` route named `webhooks.inbound`, and the body-decoding global
 * middleware switched off for that prefix.
 *
 * @internal
 */
final class InboundRoute
{
    /**
     * @param  list<string>  $middleware
     */
    public static function mount(Router $router, string $prefix = 'webhooks', array $middleware = []): Route
    {
        $prefix = trim($prefix, '/');
        self::skipBodyDecoding($prefix);

        return $router->post(($prefix === '' ? '' : $prefix.'/').'{key}', ReceiveWebhookController::class)
            ->middleware($middleware)
            ->where('key', UrlKeys::PATTERN)
            ->name(InboundUrls::ROUTE);
    }

    /**
     * Laravel's global TrimStrings / ConvertEmptyStringsToNull decode a JSON body with
     * `$request->json()` before any route runs — an unbounded body, before the size check, and
     * a changed one can break a signature. Skip them for inbound posts under the prefix.
     */
    public static function skipBodyDecoding(string $prefix): void
    {
        $prefix = trim($prefix, '/');
        $inbound = static fn (Request $request): bool => $request->isMethod('POST') && $request->is(($prefix === '' ? '' : $prefix.'/').'*');

        TrimStrings::skipWhen($inbound);
        ConvertEmptyStringsToNull::skipWhen($inbound);
    }
}
