<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Http\Resources\InboundEndpointResource;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use RoundlyConsulting\Webhooks\Testing\FakeHostResolver;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;

beforeEach(fn () => Queue::fake());

it('refuses a declared Content-Length over the limit before reading the body', function (): void {
    config()->set('webhooks.inbound.max_payload_bytes', 10);
    $endpoint = inboundEndpoint();

    $this->call('POST', $endpoint->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '5000'], '{}')
        ->assertStatus(413);
});

it('refuses a request with no content type', function (): void {
    $endpoint = inboundEndpoint();
    $request = Request::create($endpoint->url(), 'POST', [], [], [], [], '{}');
    $request->headers->remove('Content-Type');

    expect(Webhooks::inbound()->receive($endpoint->url_key, $request)->getStatusCode())->toBe(415);
});

it('refuses an RFC 9421 verification without the request', function (): void {
    $incoming = new IncomingWebhook([], '{}', CarbonImmutable::now(), str_repeat('a', 32), 300);

    try {
        app(Rfc9421Scheme::class)->verify($incoming, new EndpointSecrets([], app(MacEngine::class)));
        $this->fail('Expected a rejection.');
    } catch (WebhookSignatureException $exception) {
        expect($exception->reason())->toBe(RejectionReason::MissingSignature)
            ->and($incoming->header('X-None'))->toBeNull();
    }
});

it('never exposes the secrets it holds', function (): void {
    $secrets = new EndpointSecrets([V2_SECRET, V3_SECRET], app(MacEngine::class));

    expect($secrets->count())->toBe(2)
        ->and(print_r($secrets, true))->not->toContain('whsec_')
        ->and($secrets->__debugInfo())->toBe(['secrets' => 2])
        ->and($secrets->matches('m', 'x', SecretEncoding::StandardWebhooks))->toBeNull()
        ->and((new EndpointSecrets(['whsec_!!'], app(MacEngine::class)))->matches('m', 'x', SecretEncoding::StandardWebhooks))->toBeNull()
        ->and(app(MacEngine::class)->sign('m', ['whsec_!!'], SecretEncoding::StandardWebhooks))->toBe([]);
});

it('treats a delivery that lost its unique-index race as a duplicate', function (): void {
    $endpoint = inboundEndpoint();
    $raced = false;

    InboundDelivery::creating(function (InboundDelivery $delivery) use (&$raced): void {
        if (! $raced) {
            $raced = true;
            // Another request stored the same delivery id a moment earlier.
            InboundDelivery::factory()->create(['endpoint_id' => $delivery->endpoint_id, 'idempotency_key' => $delivery->idempotency_key]);
        }
    });

    postSigned($endpoint)->assertStatus(200)->assertJson(['status' => 'duplicate']);
    expect(InboundDelivery::query()->count())->toBe(1);
});

it('stores a delivery even when the queue is down', function (): void {
    app()->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function ($mock): void {
        $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    }));
    $endpoint = inboundEndpoint();

    postSigned($endpoint)->assertStatus(202);

    $delivery = InboundDelivery::query()->sole();
    expect($delivery->status)->toBe(InboundStatus::Received)->and($delivery->queued_at)->toBeNull();
});

it('refuses to replay a delivery that is being processed', function (): void {
    $delivery = InboundDelivery::factory()->create(['status' => InboundStatus::Processing]);

    expect(fn () => Webhooks::inbound()->replay($delivery))->toThrow(DeliveryInProgressException::class);
});

it('refuses a delivery whose endpoint cannot be loaded', function (): void {
    $inbound = InboundDelivery::factory()->create();
    $inbound->setRelation('endpoint', null);
    $outbound = OutboundDelivery::factory()->create();
    $outbound->setRelation('endpoint', null);

    expect(fn () => Webhooks::inbound()->replay($inbound))->toThrow(EndpointNotFoundException::class)
        ->and(fn () => Webhooks::outbound()->redeliver($outbound))->toThrow(EndpointNotFoundException::class)
        ->and(fn () => Webhooks::outbound()->attempts($outbound))->toThrow(EndpointNotFoundException::class);
});

it('reads datetimes back as UTC whatever they were set as', function (): void {
    $delivery = InboundDelivery::factory()->create();
    $delivery->setRawAttributes([...$delivery->getAttributes(), 'received_at' => new DateTime('2026-11-08 13:00:00', new DateTimeZone('Europe/Bratislava'))]);

    expect($delivery->received_at->toDateTimeString())->toBe('2026-11-08 12:00:00')
        ->and($delivery->received_at->timezoneName)->toBe('UTC');

    $delivery->setRawAttributes([...$delivery->getAttributes(), 'received_at' => 'garbage']);
    expect($delivery->received_at)->toBeNull();
});

it('renders no owner when the owner is gone', function (): void {
    $team = team();
    $endpoint = Webhooks::for($team)->inbound()->create()->endpoint;
    $team->delete();

    expect((new InboundEndpointResource($endpoint->load('owner')))->resolve(Request::create('/'))['owner'])->toBeNull()
        ->and((new InboundEndpointResource($endpoint->withoutRelations()))->resolve(Request::create('/'))['owner'])->toBeNull();
});

it('lists a delivery\'s attempts through the relation', function (): void {
    $attempt = OutboundAttempt::factory()->create();

    expect($attempt->delivery?->attemptLog()->sole()->is($attempt))->toBeTrue();
});

it('aliases safe-fetch\'s fake resolver for host tests', function (): void {
    $dns = FakeHostResolver::map(['hooks.example.com' => '93.184.216.34'])->install();

    expect(Webhooks::outbound()->create('https://hooks.example.com/in')->endpoint->url_host)->toBe('hooks.example.com');
    $dns->assertLookedUp('hooks.example.com', 1);
});

it('the signed request builder posts through the kernel', function (): void {
    $endpoint = inboundEndpoint();
    $data = SignedWebhookRequest::for($endpoint)->id('msg_x')->at(CarbonImmutable::now())->withHeader('X-Extra', '1')->toRequest();

    expect($data->headers['webhook-id'])->toBe('msg_x')
        ->and($data->headers['X-Extra'])->toBe('1')
        ->and($data->uri)->toBe($endpoint->url());
});
