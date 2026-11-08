<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\DataTransferObjects\CreatedInboundEndpoint;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * The README must be true: its PHP parses, and its one example runs as written.
 */
it('has PHP blocks that parse', function (): void {
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(__DIR__.'/../../README.md'), $blocks);

    expect($blocks[1])->toHaveCount(3);

    foreach ($blocks[1] as $block) {
        token_get_all('<?php '.$block, TOKEN_PARSE);
    }
});

it('runs the usage example', function (): void {
    Queue::fake();
    $team = team();

    // The README's create call, line for line — run below against real DNS (no faked resolver):
    // the SSRF guard checks the host on save, so a URL that does not resolve publicly fails here.
    expect((string) file_get_contents(__DIR__.'/../../README.md'))
        ->toContain("\$outbound = Webhooks::for(\$team)->outbound()->create('https://example.com/webhooks', events: ['invoice.*']);");

    // A verbatim copy of the README's calls.
    $inbound = Webhooks::for($team)->inbound()->create(handler: 'billing');
    $outbound = Webhooks::for($team)->outbound()->create('https://example.com/webhooks', events: ['invoice.*']);
    $delivery = Webhooks::send($outbound->endpoint, 'invoice.paid', ['invoice' => 42]);

    expect($inbound)->toBeInstanceOf(CreatedInboundEndpoint::class)
        ->and($inbound->url)->toContain('/webhooks/')
        ->and($inbound->secret)->toStartWith('whsec_')
        ->and($outbound->secret)->toStartWith('whsec_')
        ->and($delivery)->toBeInstanceOf(OutboundDelivery::class);
});
