<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\InboundWebhooks;
use RoundlyConsulting\Webhooks\Accessors\OutboundWebhooks;
use RoundlyConsulting\Webhooks\Accessors\OwnerWebhooks;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Actions\Outbound\RedeliverWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\SendWebhookAction;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers\BillingWebhookHandler;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * The public API — Actions → Manager → Facade (+ fake): the pins, every facade and sub-accessor
 * method through the facade, one DI resolution, the raw action, cross-scope refusals, and every
 * fake assertion passing and failing.
 */
beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

it('pins the facade contract', function (): void {
    expect(Webhooks::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $aliases = $composer['extra']['laravel']['aliases'];
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe(['Webhooks' => Webhooks::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\Webhooks'))->toBeFalse()
        ->and($coreAliases)->not->toContain('webhooks');
});

it('hands out the sub-accessors', function (): void {
    $team = team();

    expect(Webhooks::inbound())->toBeInstanceOf(InboundWebhooks::class)
        ->and(Webhooks::outbound())->toBeInstanceOf(OutboundWebhooks::class)
        ->and(Webhooks::for($team))->toBeInstanceOf(OwnerWebhooks::class)
        ->and(Webhooks::for($team)->owner()->is($team))->toBeTrue()
        ->and(Webhooks::schemes())->toBeInstanceOf(SchemeRegistry::class)
        ->and(Webhooks::handlers())->toBeInstanceOf(HandlerRegistry::class)
        ->and(Webhooks::schemes()->names())->toBe(['default', 'rfc9421'])
        ->and(Webhooks::handlers()->aliases())->toBe(['billing']);
});

it('runs the same flow through the facade, an injected manager and the raw action', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $endpoint = outboundEndpoint();

    $viaFacade = Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 1]);
    $manager = app(WebhooksManager::class);
    $viaManager = $manager->outbound()->send($endpoint, 'invoice.paid', ['invoice' => 2]);
    $viaAction = app(SendWebhookAction::class)->execute($endpoint, 'invoice.paid', ['invoice' => 3]);

    expect($manager)->toBe(app(WebhooksManager::class))
        ->and(OutboundDelivery::query()->count())->toBe(3)
        ->and([$viaFacade, $viaManager, $viaAction])->each->toBeInstanceOf(OutboundDelivery::class);
    Queue::assertPushed(DeliverWebhook::class, 3);

    attempt($viaFacade->refresh());
    Webhooks::outbound()->redeliver($viaFacade->refresh());
    app(RedeliverWebhookAction::class)->execute(attempt($viaFacade)->refresh());

    expect($viaFacade->refresh()->series)->toBe(3);
});

it('manages inbound endpoints through the facade', function (): void {
    $created = Webhooks::inbound()->create(handler: 'billing', name: 'Stripe', options: new InboundEndpointOptions(rateLimitPerMinute: 10, toleranceSeconds: 60));
    $endpoint = $created->endpoint;

    expect($created->url)->toEndWith('/webhooks/'.$endpoint->url_key)
        ->and($created->secret)->toStartWith('whsec_')
        ->and($endpoint->rate_limit_per_minute)->toBe(10)
        ->and(Webhooks::inbound()->find($endpoint->getKey())->is($endpoint))->toBeTrue()
        ->and(Webhooks::inbound()->endpoints()->modelKeys())->toBe([$endpoint->getKey()]);

    Webhooks::inbound()->update($endpoint, new InboundEndpointChanges(name: 'Stripe live', clear: ['tolerance_seconds']));
    expect($endpoint->refresh()->name)->toBe('Stripe live')->and($endpoint->tolerance_seconds)->toBeNull();

    Webhooks::inbound()->disable($endpoint);
    expect($endpoint->refresh()->enabled)->toBeFalse();
    Webhooks::inbound()->enable($endpoint);
    expect($endpoint->refresh()->enabled)->toBeTrue();

    $rotated = Webhooks::inbound()->rotateSecret($endpoint);
    expect($rotated->secret)->not->toBe($created->secret)
        ->and($endpoint->refresh()->previous_secret)->toBe($created->secret);

    $request = SignedWebhookRequest::for($endpoint)->toRequest();
    $verified = Webhooks::inbound()->verify($endpoint, Request::create($request->uri, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_WEBHOOK_ID' => $request->headers['webhook-id'],
        'HTTP_WEBHOOK_TIMESTAMP' => $request->headers['webhook-timestamp'],
        'HTTP_WEBHOOK_SIGNATURE' => $request->headers['webhook-signature'],
    ], $request->body));
    expect($verified->slot)->toBe(SecretSlot::Current)->and($verified->idIsSigned)->toBeTrue();

    Webhooks::inbound()->delete($endpoint);
    expect(fn () => Webhooks::inbound()->find($endpoint->getKey()))->toThrow(EndpointNotFoundException::class);
});

it('replays an inbound delivery through the facade', function (): void {
    $endpoint = inboundEndpoint(['handler' => 'billing']);
    postSigned($endpoint)->assertStatus(202);
    $delivery = Webhooks::inbound()->deliveries($endpoint)->sole();

    $replayed = Webhooks::inbound()->replay($delivery);

    expect($replayed->runs)->toBe(2)->and($replayed->status)->toBe(InboundStatus::Queued)
        ->and(Webhooks::inbound()->deliveries()->count())->toBe(1);
    Queue::assertPushed(ProcessInboundDelivery::class, fn (ProcessInboundDelivery $job): bool => $job->replay && $job->run === 2);
});

it('manages outbound endpoints through the facade', function (): void {
    $created = Webhooks::outbound()->create('https://hooks.example.com/in', events: ['invoice.*'], name: 'ERP', headers: ['X-Tenant' => 'acme'], options: new OutboundEndpointOptions(rateLimitPerMinute: 5, description: 'ledger'));
    $endpoint = $created->endpoint;

    expect($created->secret)->toStartWith('whsec_')
        ->and($endpoint->headers)->toBe(['x-tenant' => 'acme'])
        ->and($endpoint->url_host)->toBe('hooks.example.com')
        ->and(Webhooks::outbound()->find($endpoint->getKey())->is($endpoint))->toBeTrue()
        ->and(Webhooks::outbound()->endpoints()->modelKeys())->toBe([$endpoint->getKey()]);

    Webhooks::outbound()->update($endpoint, new OutboundEndpointChanges(url: 'https://other.example.com/in', events: ['*'], name: 'ERP 2', headers: ['X-Tenant' => 'beta'], clear: ['description']));
    expect($endpoint->refresh()->url_host)->toBe('other.example.com')->and($endpoint->description)->toBeNull();

    Webhooks::outbound()->disable($endpoint);
    expect($endpoint->refresh()->enabled)->toBeFalse();
    Webhooks::outbound()->enable($endpoint);
    expect($endpoint->refresh()->enabled)->toBeTrue()->and($endpoint->consecutive_failures)->toBe(0);

    Webhooks::outbound()->rotateSecret($endpoint);
    expect($endpoint->refresh()->previous_secret)->toBe($created->secret);

    Http::fake(['*' => Http::response('ok')]);
    $delivery = attempt(Webhooks::outbound()->send($endpoint, 'invoice.paid', ['n' => 1]));
    expect(Webhooks::outbound()->attempts($delivery))->toHaveCount(1)
        ->and(Webhooks::outbound()->deliveries($endpoint)->count())->toBe(1)
        ->and(Webhooks::outbound()->deliveries()->count())->toBe(1)
        ->and(Webhooks::outbound()->dispatchDue())->toBe(0);

    Webhooks::outbound()->delete($endpoint);
    expect(fn () => Webhooks::outbound()->find($endpoint->getKey()))->toThrow(EndpointNotFoundException::class);
});

it('broadcasts to the owner-less endpoints only', function (): void {
    $app = outboundEndpoint(events: ['system.*']);
    outboundEndpoint(events: ['system.*'], attributes: ['owner_type' => team()->getMorphClass(), 'owner_id' => 1]);

    $deliveries = Webhooks::broadcast('system.maintenance', ['at' => 'tonight']);

    expect($deliveries)->toHaveCount(1)->and($deliveries->first()?->endpoint_id)->toBe($app->getKey());
});

it('prunes through the facade', function (): void {
    expect(Webhooks::prune(new PruneOptions(dryRun: true))->dryRun)->toBeTrue()
        ->and(Webhooks::prune()->total())->toBe(0);
});

it('scopes every call of an owner handle to that owner', function (): void {
    $acme = team('Acme');
    $other = team('Other');
    $mine = Webhooks::for($acme)->outbound()->create('https://hooks.example.com/in')->endpoint;
    $theirs = Webhooks::for($other)->outbound()->create('https://hooks.example.com/in')->endpoint;
    $myInbound = Webhooks::for($acme)->inbound()->create()->endpoint;
    $theirInbound = Webhooks::for($other)->inbound()->create()->endpoint;

    expect($mine->owner_id)->toBe($acme->getKey())
        ->and(Webhooks::for($acme)->outbound()->endpoints()->modelKeys())->toBe([$mine->getKey()])
        ->and(Webhooks::for($acme)->inbound()->endpoints()->modelKeys())->toBe([$myInbound->getKey()])
        ->and(Webhooks::for($acme)->outbound()->deliveries()->count())->toBe(0)
        ->and(Webhooks::for($acme)->inbound()->deliveries()->count())->toBe(0);

    $refusals = [
        fn () => Webhooks::for($acme)->outbound()->find($theirs->getKey()),
        fn () => Webhooks::for($acme)->outbound()->send($theirs, 'invoice.paid', []),
        fn () => Webhooks::for($acme)->outbound()->update($theirs, new OutboundEndpointChanges(name: 'x')),
        fn () => Webhooks::for($acme)->outbound()->disable($theirs),
        fn () => Webhooks::for($acme)->outbound()->rotateSecret($theirs),
        fn () => Webhooks::for($acme)->outbound()->delete($theirs),
        fn () => Webhooks::for($acme)->outbound()->deliveries($theirs),
        fn () => Webhooks::for($acme)->outbound()->redeliver(OutboundDelivery::factory()->create(['endpoint_id' => $theirs->getKey()])),
        fn () => Webhooks::for($acme)->outbound()->attempts(OutboundDelivery::factory()->create(['endpoint_id' => $theirs->getKey()])),
        fn () => Webhooks::for($acme)->inbound()->find($theirInbound->getKey()),
        fn () => Webhooks::for($acme)->inbound()->update($theirInbound, new InboundEndpointChanges(name: 'x')),
        fn () => Webhooks::for($acme)->inbound()->enable($theirInbound),
        fn () => Webhooks::for($acme)->inbound()->rotateSecret($theirInbound),
        fn () => Webhooks::for($acme)->inbound()->delete($theirInbound),
        fn () => Webhooks::for($acme)->inbound()->deliveries($theirInbound),
        fn () => Webhooks::for($acme)->inbound()->verify($theirInbound, Request::create('/x', 'POST')),
        fn () => Webhooks::for($acme)->inbound()->replay(InboundDelivery::factory()->create(['endpoint_id' => $theirInbound->getKey()])),
    ];

    foreach ($refusals as $refusal) {
        expect($refusal)->toThrow(EndpointNotFoundException::class);
    }
});

it('the_owner_trait_goes_through_the_manager', function (): void {
    $team = team();
    $fake = Webhooks::fake();
    $endpoint = outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);

    $team->broadcastWebhook('invoice.paid', ['invoice' => 7]);
    $team->webhooks()->outbound()->send($endpoint, 'invoice.paid', ['invoice' => 8]);

    expect($team->outboundWebhookEndpoints()->count())->toBe(1)
        ->and($team->inboundWebhookEndpoints()->count())->toBe(0);
    $fake->assertBroadcast('invoice.paid', $team);
    $fake->assertSentTimes(2, $endpoint, 'invoice.paid');
    $fake->assertSent($endpoint, 'invoice.paid', fn (array $payload): bool => $payload['invoice'] === 7);
});

it('the_fake_refuses_what_production_refuses', function (): void {
    $endpoint = outboundEndpoint(events: ['invoice.*']);
    $disabled = outboundEndpoint(attributes: ['enabled' => false]);
    $fake = Webhooks::fake();

    expect(fn () => Webhooks::send($disabled, 'invoice.paid', []))->toThrow(EndpointNotAcceptingException::class)
        ->and(fn () => Webhooks::send($endpoint, 'order.paid', []))->toThrow(EndpointNotAcceptingException::class)
        ->and(fn () => Webhooks::send($endpoint, 'Invoice Paid', []))->toThrow(InvalidEventTypeException::class)
        ->and(fn () => Webhooks::broadcast('Not Valid', []))->toThrow(InvalidEventTypeException::class)
        ->and(fn () => Webhooks::send($endpoint, 'invoice.paid', ['pad' => str_repeat('x', 300000)]))->toThrow(PayloadTooLargeException::class)
        ->and(fn () => Webhooks::outbound()->redeliver(OutboundDelivery::factory()->status(OutboundStatus::Retrying)->create(['endpoint_id' => $endpoint->getKey()])))
        ->toThrow(DeliveryInProgressException::class)
        ->and(fn () => Webhooks::inbound()->replay(InboundDelivery::factory()->create(['payload' => null, 'payload_pruned_at' => CarbonImmutable::now()])))
        ->toThrow(PayloadPrunedException::class)
        ->and(fn () => Webhooks::inbound()->replay(InboundDelivery::factory()->create(['status' => InboundStatus::Processing])))
        ->toThrow(DeliveryInProgressException::class);

    $fake->assertNothingSent();
});

it('passes receive and verify through to the real pipeline under the fake', function (): void {
    Webhooks::fake();
    $endpoint = inboundEndpoint(['handler' => 'billing']);

    postSigned($endpoint)->assertStatus(202);
    SignedWebhookRequest::for($endpoint)->tamper()->send()->assertStatus(401);

    expect(InboundDelivery::query()->count())->toBe(1);
});

it('lets a host register handlers and schemes at run time', function (): void {
    Webhooks::handlers()->register('ledger', BillingWebhookHandler::class);

    expect(Webhooks::handlers()->aliases())->toBe(['billing', 'ledger'])
        ->and(Webhooks::handlers()->has('ledger'))->toBeTrue();
});
