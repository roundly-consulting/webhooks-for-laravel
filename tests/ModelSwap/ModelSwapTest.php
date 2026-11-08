<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomInboundDelivery;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomInboundEndpoint;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomOutboundAttempt;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomOutboundDelivery;
use RoundlyConsulting\Webhooks\Tests\Fixtures\Swapped\CustomOutboundEndpoint;

beforeEach(fn () => Queue::fake());

it('creates inbound endpoints as the host class', function (): void {
    expect('webhooks.models.inbound_endpoint')->toHonourModelSwap(CustomInboundEndpoint::class, function (): array {
        Webhooks::inbound()->create(handler: 'billing');

        return Webhooks::inbound()->endpoints()->all();
    });
});

it('stores inbound deliveries as the host class', function (): void {
    expect('webhooks.models.inbound_delivery')->toHonourModelSwap(CustomInboundDelivery::class, function (): array {
        postSigned(inboundEndpoint())->assertStatus(202);

        return Webhooks::inbound()->deliveries()->get()->all();
    });
});

it('creates outbound endpoints as the host class', function (): void {
    publicDns();

    expect('webhooks.models.outbound_endpoint')->toHonourModelSwap(CustomOutboundEndpoint::class, function (): array {
        Webhooks::outbound()->create('https://hooks.example.com/in');

        return Webhooks::outbound()->endpoints()->all();
    });
});

it('stores outbound deliveries and attempts as the host classes', function (): void {
    publicDns();
    Http::fake(['*' => Http::response('ok')]);

    expect('webhooks.models.outbound_delivery')->toHonourModelSwap(CustomOutboundDelivery::class, function (): array {
        Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 1]);

        return Webhooks::outbound()->deliveries()->get()->all();
    });

    expect('webhooks.models.outbound_attempt')->toHonourModelSwap(CustomOutboundAttempt::class, function (): array {
        $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 2]));

        return Webhooks::outbound()->attempts($delivery)->all();
    });
});
