<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Webhooks\Facades\Webhooks;

it('registers no inbound route when it is switched off', function (): void {
    $endpoint = inboundEndpoint();

    expect(Route::has('webhooks.inbound'))->toBeFalse()
        ->and($endpoint->url())->toBe(url('webhooks/'.$endpoint->url_key));

    $this->postJson('/webhooks/'.$endpoint->url_key, [])->assertNotFound();
});

it('route_macro_mounts_the_pipeline', function (): void {
    Route::webhooks('hooks', middleware: ['throttle:60,1']);
    app('router')->getRoutes()->refreshNameLookups();
    $endpoint = inboundEndpoint(['handler' => 'billing']);

    $route = Route::getRoutes()->getByName('webhooks.inbound');

    expect($route?->uri())->toBe('hooks/{key}')
        ->and($route?->methods())->toContain('POST')
        ->and($route?->gatherMiddleware())->toContain('throttle:60,1');
    postSigned($endpoint)->assertStatus(202);
    $this->postJson('/hooks/'.str_repeat('z', 32), [])->assertNotFound();
});

it('route_macro_skips_body_decoding_middleware_for_its_prefix', function (): void {
    Route::webhooks('/hooks/');
    $run = static function (string $path, string $middleware): mixed {
        $request = Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"  padded  ","empty":""}');

        return app($middleware)->handle($request, static fn (Request $request): mixed => $request->json()->all());
    };

    $key = '/hooks/'.str_repeat('a', 32);

    expect($run($key, TrimStrings::class))->toBe(['name' => '  padded  ', 'empty' => ''])
        ->and($run($key, ConvertEmptyStringsToNull::class))->toBe(['name' => '  padded  ', 'empty' => ''])
        // Only a real key: a host's own route under the prefix keeps the middleware.
        ->and($run('/hooks/abc', TrimStrings::class))->toBe(['name' => 'padded', 'empty' => ''])
        ->and($run('/elsewhere/abc', TrimStrings::class))->toBe(['name' => 'padded', 'empty' => ''])
        ->and($run('/elsewhere/abc', ConvertEmptyStringsToNull::class))->toBe(['name' => '  padded  ', 'empty' => null]);
});

it('endpoint_url_uses_the_macro_route', function (): void {
    Route::webhooks('incoming/hooks');
    app('router')->getRoutes()->refreshNameLookups();

    $created = Webhooks::inbound()->create();

    expect($created->url)->toBe(url('incoming/hooks/'.$created->endpoint->url_key))->and($created->url)->not->toContain('/webhooks/')
        ->and($created->endpoint->url())->toBe($created->url);
});
