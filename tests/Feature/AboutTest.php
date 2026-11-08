<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('renders its about section without leaking a secret or a header value', function (): void {
    $endpoint = outboundEndpoint(attributes: ['headers' => ['x-api-key' => 'header-value-secret']]);
    publicDns();

    expect('webhooks')->toLeakNoSecrets(
        secrets: [V2_SECRET, 'header-value-secret', $endpoint->url],
        mustRender: ['Route prefix', '/webhooks/{key}', 'Queue', 'SSRF guard', 'ON', 'https only', 'Schemes', 'default, rfc9421'],
    );
});

it('shows the switches the way an env string means them', function (): void {
    config()->set('webhooks.ssrf.enabled', 'off');
    config()->set('webhooks.outbound.allow_http', 'on');
    config()->set('webhooks.inbound.route.enabled', '0');

    Artisan::call('about', ['--only' => 'webhooks']);

    expect(Artisan::output())->toMatch('/SSRF guard\W+OFF/')->toMatch('/Outbound schemes\W+http, https/')->toMatch('/Route prefix\W+OFF/');
});
