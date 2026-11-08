<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Events\InvoicePaid;

beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

it('an_event_implementing_the_contract_is_broadcast', function (): void {
    $team = team();
    $endpoint = outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    outboundEndpoint('https://other.example.com/in', events: ['invoice.*']);   // app-level: not this owner's

    event(new InvoicePaid(42, $team));

    $delivery = OutboundDelivery::query()->sole();
    expect($delivery->endpoint_id)->toBe($endpoint->getKey())
        ->and(json_decode((string) $delivery->body, true)['data'])->toBe(['invoice' => 42]);
    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('the_fake_records_an_event_broadcast', function (): void {
    $fake = Webhooks::fake();

    event(new InvoicePaid(7));

    $fake->assertBroadcast('invoice.paid', payload: fn (array $payload): bool => $payload === ['invoice' => 7]);
});
