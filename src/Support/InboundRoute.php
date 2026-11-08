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
 * route — the `POST {prefix}/{key}` route named `webhooks.inbound`, and the input-rewriting
 * global middleware switched off for that prefix.
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
     * Laravel's global TrimStrings / ConvertEmptyStringsToNull walk and rewrite every value of a
     * JSON request's decoded input — work the pipeline never uses (it verifies and decodes the
     * raw body itself). Skip them for inbound posts only: `POST {prefix}/{key}` with a key of the
     * real shape, on the route's domain when it has one — every other post of the host keeps
     * them. This does not bound the body: `Request::capture()` has read and decoded a JSON body
     * before any middleware runs, so only the web server's body limit comes before that.
     */
    public static function skipBodyDecoding(string $prefix, ?string $domain = null): void
    {
        $prefix = trim($prefix, '/');
        $path = '#^'.($prefix === '' ? '' : preg_quote($prefix, '#').'/').UrlKeys::PATTERN.'$#D';
        // A `{parameter}` of a route domain matches one label, as Laravel's domain routing does.
        $host = $domain === null ? null : '#^'.preg_replace('/\\\{[^.]+?\\\}/', '[^.]+', preg_quote(strtolower($domain), '#')).'$#D';
        $inbound = static fn (Request $request): bool => $request->isMethod('POST')
            && preg_match($path, $request->decodedPath()) === 1
            && ($host === null || preg_match($host, strtolower($request->getHost())) === 1);

        TrimStrings::skipWhen($inbound);
        ConvertEmptyStringsToNull::skipWhen($inbound);
    }
}
