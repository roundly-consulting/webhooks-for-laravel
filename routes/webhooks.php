<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Webhooks\Http\Controllers\ReceiveWebhookController;
use RoundlyConsulting\Webhooks\Support\InboundUrls;
use RoundlyConsulting\Webhooks\Support\UrlKeys;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

// The inbound endpoint: no session, no CSRF — a sender has neither. Unknown keys 404.
$attributes = ['prefix' => WebhooksConfig::routePrefix(), 'middleware' => WebhooksConfig::routeMiddleware()];
$domain = WebhooksConfig::routeDomain();

Route::group($domain === null ? $attributes : [...$attributes, 'domain' => $domain], static function (): void {
    Route::post('{key}', ReceiveWebhookController::class)
        ->where('key', UrlKeys::PATTERN)
        ->name(InboundUrls::ROUTE);
});
