<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('registers no inbound route when it is switched off', function (): void {
    $endpoint = inboundEndpoint();

    expect(Route::has('webhooks.inbound'))->toBeFalse()
        ->and($endpoint->url())->toBe(url('webhooks/'.$endpoint->url_key));

    $this->postJson('/webhooks/'.$endpoint->url_key, [])->assertNotFound();
});
