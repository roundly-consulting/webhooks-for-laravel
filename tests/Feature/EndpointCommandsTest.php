<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

it('endpoints_command_lists_without_secrets', function (): void {
    $team = team();
    $inbound = Webhooks::for($team)->inbound()->create(handler: 'billing', name: 'Stripe');
    $outbound = Webhooks::outbound()->create('https://hooks.example.com/in?token=s3cr3t', events: ['invoice.*'], name: 'ERP', headers: ['X-Api-Key' => 'k3y']);

    Artisan::call('webhooks:endpoints');
    $all = Artisan::output();

    expect($all)->toContain('Stripe')->toContain('billing')->toContain('Team:'.$team->getKey())
        ->toContain('/'.$inbound->endpoint->url_key)
        ->toContain('hooks.example.com')->toContain('invoice.*')
        ->not->toContain($inbound->secret)
        ->not->toContain($outbound->secret)
        ->not->toContain('s3cr3t')
        ->not->toContain('k3y')
        ->not->toContain('RoundlyConsulting');

    Artisan::call('webhooks:endpoints', ['--owner' => $team->getMorphClass().':'.$team->getKey()]);
    expect(Artisan::output())->toContain('Stripe')->not->toContain('ERP');

    $this->artisan('webhooks:endpoints', ['--owner' => 'Nobody:999'])->expectsOutputToContain('No endpoints')->assertSuccessful();
    $this->artisan('webhooks:endpoints', ['--owner' => 'no-colon'])->expectsOutputToContain('morph-class:id')->assertExitCode(2);
});

it('enable_command_enables_either_direction', function (): void {
    $outbound = outboundEndpoint(attributes: ['enabled' => false]);
    $inbound = inboundEndpoint(['enabled' => false]);

    $this->artisan('webhooks:enable', ['direction' => 'outbound', 'endpoint' => (string) $outbound->getKey()])->expectsOutputToContain('enabled')->assertSuccessful();
    $this->artisan('webhooks:enable', ['direction' => 'inbound', 'endpoint' => (string) $inbound->getKey()])->assertSuccessful();

    expect($outbound->refresh()->enabled)->toBeTrue()->and($inbound->refresh()->enabled)->toBeTrue();

    $this->artisan('webhooks:enable', ['direction' => 'sideways', 'endpoint' => '1'])->assertExitCode(2);
    $this->artisan('webhooks:enable', ['direction' => 'outbound', 'endpoint' => '999999'])->expectsOutputToContain('not found')->assertFailed();
});

it('ping_sends_a_test_event', function (): void {
    $endpoint = outboundEndpoint(events: ['invoice.*']);   // does not subscribe to webhook.ping
    config()->set('webhooks.outbound.event_types', ['invoice.paid']);

    $this->artisan('webhooks:ping', ['endpoint' => (string) $endpoint->getKey()])->expectsOutputToContain('webhook.ping')->assertSuccessful();

    $delivery = OutboundDelivery::query()->sole();
    expect($delivery->event_type)->toBe('webhook.ping')
        ->and(json_decode((string) $delivery->body, true))->toMatchArray(['type' => 'webhook.ping', 'data' => ['ping' => true]]);
    Queue::assertPushed(DeliverWebhook::class, 1);

    $disabled = outboundEndpoint(attributes: ['enabled' => false]);
    $this->artisan('webhooks:ping', ['endpoint' => (string) $disabled->getKey()])->expectsOutputToContain('disabled')->assertFailed();
    $this->artisan('webhooks:ping', ['endpoint' => '999999'])->expectsOutputToContain('not found')->assertFailed();
});

it('ping goes through the facade and the fake', function (): void {
    $team = team();
    $endpoint = outboundEndpoint(attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $fake = Webhooks::fake();

    $delivery = Webhooks::for($team)->outbound()->ping($endpoint);

    expect($delivery->exists)->toBeFalse();
    $fake->assertSent($endpoint, 'webhook.ping');
    expect(fn () => Webhooks::for(team('Other'))->outbound()->ping($endpoint))->toThrow(EndpointNotFoundException::class)
        ->and(fn () => Webhooks::outbound()->ping(outboundEndpoint(attributes: ['enabled' => false])))->toThrow(EndpointNotAcceptingException::class);
});
