<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Testing\RecordedBroadcast;
use RoundlyConsulting\Webhooks\Testing\SentWebhook;
use RoundlyConsulting\Webhooks\Testing\WebhooksFake;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * `Webhooks::fake()`: every recorded verb has an assertion and a negative, each passing and
 * failing — reached through the facade, `Webhooks::for($team)` and the `HasWebhooks` trait.
 */
beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

function fails(Closure $assertion, string $message): void
{
    expect($assertion)->toThrow(ExpectationFailedException::class, $message);
}

it('fake_send_assertions_pass_and_fail', function (): void {
    $endpoint = outboundEndpoint(events: ['invoice.*']);
    $other = outboundEndpoint('https://other.example.com/in');
    $fake = Webhooks::fake();

    expect(app(WebhooksManager::class))->toBeInstanceOf(WebhooksFake::class);

    $fake->assertNothingSent();
    $delivery = Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]);

    // A faked send returns an unsaved delivery.
    expect($delivery->exists)->toBeFalse()->and($delivery->getKey())->toBeNull()
        ->and($delivery->webhookId())->toStartWith('msg_')
        ->and(OutboundDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $fake->assertSent($endpoint);
    $fake->assertSent($endpoint, 'invoice.paid', fn (array $payload): bool => $payload === ['invoice' => 42]);
    $fake->assertSent(fn (SentWebhook $sent): bool => $sent->body !== '' && ! $sent->broadcast);
    $fake->assertSentTimes(1, $endpoint);
    $fake->assertNotSent($other);
    Webhooks::assertSent($endpoint, 'invoice.paid');
    expect($fake->sent())->toHaveCount(1);

    fails(fn () => $fake->assertSent($other), 'Expected a webhook to be sent');
    fails(fn () => $fake->assertSent($endpoint, 'invoice.void'), '[invoice.void]');
    fails(fn () => $fake->assertSentTimes(2, $endpoint), 'Expected 2 matching webhook(s)');
    fails(fn () => $fake->assertNotSent($endpoint), 'Expected no matching webhook');
    fails(fn () => $fake->assertNothingSent(), 'Expected no webhook to be sent, but 1 were.');
});

it('assert_sent_accepts_an_event_type', function (): void {
    $endpoint = outboundEndpoint(events: ['invoice.*']);
    $fake = Webhooks::fake();

    Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]);
    Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 43]);

    $fake->assertSent('invoice.paid');
    $fake->assertSent('invoice.paid', payload: fn (array $payload): bool => $payload['invoice'] === 42);
    $fake->assertSentTimes(2, 'invoice.paid');
    $fake->assertNotSent('invoice.void');

    fails(fn () => $fake->assertSent('invoice.void'), '[invoice.void]');
    fails(fn () => $fake->assertSent('invoice.paid', payload: fn (array $payload): bool => $payload['invoice'] === 7), '[invoice.paid]');
    fails(fn () => $fake->assertNotSent('invoice.paid'), 'but 2 were');
    expect(fn () => $fake->assertSent('invoice.paid', 'invoice.void'))->toThrow(InvalidArgumentException::class, 'once');
});

it('sent_webhook_carries_owner_and_webhook_id', function (): void {
    $team = team();
    $endpoint = outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $fake = Webhooks::fake();

    $delivery = Webhooks::for($team)->outbound()->send($endpoint, 'invoice.paid', ['invoice' => 42]);
    Webhooks::send(outboundEndpoint(), 'invoice.paid', []);

    [$owned, $appLevel] = $fake->sent()->all();
    expect($owned->owner?->is($team))->toBeTrue()
        ->and($owned->webhookId)->toBe($delivery->webhookId())
        ->and($appLevel->owner)->toBeNull();
});

it('fake_broadcast_assertions_pass_and_fail', function (): void {
    $team = team();
    outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $fake = Webhooks::fake();

    $fake->assertNothingBroadcast();
    $fake->assertNotBroadcast('invoice.paid');
    fails(fn () => $fake->assertBroadcast('invoice.paid'), 'to be broadcast');

    Webhooks::for($team)->broadcast('invoice.paid', ['invoice' => 42]);

    $fake->assertBroadcast('invoice.paid');
    $fake->assertBroadcast('invoice.paid', $team);
    $fake->assertNotBroadcast('invoice.void');
    $fake->assertNotBroadcast('invoice.paid', team('Other'));
    expect($fake->broadcasts()->sole())->toBeInstanceOf(RecordedBroadcast::class);

    fails(fn () => $fake->assertBroadcast('invoice.paid', team('Third')), 'to be broadcast');
    fails(fn () => $fake->assertNotBroadcast('invoice.paid', $team), 'not to be broadcast, but it was 1 time(s)');
    fails(fn () => $fake->assertNothingBroadcast(), 'Expected nothing to be broadcast, but 1 broadcast(s) were.');
});

it('assert_broadcast_matches_the_payload', function (): void {
    $fake = Webhooks::fake();

    Webhooks::broadcast('invoice.paid', new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['invoice' => 42];
        }
    });
    Webhooks::broadcast('invoice.void', collect(['invoice' => 43]));

    $fake->assertBroadcast('invoice.paid', payload: fn (array $payload): bool => $payload === ['invoice' => 42]);
    $fake->assertBroadcast('invoice.void', payload: fn (array $payload): bool => $payload === ['invoice' => 43]);

    fails(fn () => $fake->assertBroadcast('invoice.paid', payload: fn (array $payload): bool => $payload === []), 'to be broadcast');
});

it('assert_broadcast_times_counts', function (): void {
    $team = team();
    $fake = Webhooks::fake();

    Webhooks::broadcast('invoice.paid', ['invoice' => 1]);
    Webhooks::for($team)->broadcast('invoice.paid', ['invoice' => 2]);

    $fake->assertBroadcastTimes(2, 'invoice.paid');
    $fake->assertBroadcastTimes(1, 'invoice.paid', $team);
    $fake->assertBroadcastTimes(0, 'invoice.void');

    fails(fn () => $fake->assertBroadcastTimes(3, 'invoice.paid'), 'to be broadcast 3 time(s), but it was 2');
});

it('fake_sees_trait_broadcasts', function (): void {
    $team = team();
    $endpoint = outboundEndpoint(events: ['invoice.*'], attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $fake = Webhooks::fake();

    $team->broadcastWebhook('invoice.paid', ['invoice' => 7]);

    $fake->assertBroadcast('invoice.paid', $team, fn (array $payload): bool => $payload === ['invoice' => 7]);
    $fake->assertSent($endpoint, 'invoice.paid');
    fails(fn () => $fake->assertNothingBroadcast(), 'Expected nothing to be broadcast');
});

it('fake_redelivery_assertions_pass_and_fail', function (): void {
    $team = team();
    $endpoint = outboundEndpoint(attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $finished = OutboundDelivery::factory()->create(['endpoint_id' => $endpoint->getKey()]);
    $fake = Webhooks::fake();

    $fake->assertNothingRedelivered();
    fails(fn () => $fake->assertRedelivered($finished), 'to be redelivered');

    Webhooks::for($team)->outbound()->redeliver($finished);

    $fake->assertRedelivered($finished);
    expect($fake->redelivered()->sole()->is($finished))->toBeTrue();
    fails(fn () => $fake->assertNothingRedelivered(), 'Expected nothing to be redelivered, but 1');
});

it('fake_replay_assertions_pass_and_fail', function (): void {
    $team = team();
    $endpoint = inboundEndpoint(['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $inbound = InboundDelivery::factory()->create(['endpoint_id' => $endpoint->getKey()]);
    $fake = Webhooks::fake();

    $fake->assertNothingReplayed();
    fails(fn () => $fake->assertReplayed($inbound), 'to be replayed');

    Webhooks::for($team)->inbound()->replay($inbound);

    $fake->assertReplayed($inbound);
    expect($fake->replayed()->sole()->is($inbound))->toBeTrue()
        ->and($inbound->refresh()->runs)->toBe(1);
    fails(fn () => $fake->assertNothingReplayed(), 'Expected nothing to be replayed, but 1');
});

it('fake_rotation_assertions_pass_and_fail', function (): void {
    $team = team();
    $out = outboundEndpoint(attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $in = inboundEndpoint();
    $fake = Webhooks::fake();

    $fake->assertNoSecretsRotated();
    $fake->assertSecretNotRotated($out);
    fails(fn () => $fake->assertSecretRotated($out), 'to be rotated');

    Webhooks::for($team)->outbound()->rotateSecret($out);
    Webhooks::inbound()->rotateSecret($in);

    $fake->assertSecretRotated($out);
    $fake->assertSecretRotated($in);
    fails(fn () => $fake->assertSecretNotRotated($out), 'not to be rotated');
    fails(fn () => $fake->assertNoSecretsRotated(), 'Expected no secret to be rotated, but 2 were.');
});

it('fake_disable_assertions_pass_and_fail', function (): void {
    $team = team();
    $out = outboundEndpoint(attributes: ['owner_type' => $team->getMorphClass(), 'owner_id' => $team->getKey()]);
    $in = inboundEndpoint();
    $fake = Webhooks::fake();

    $fake->assertNoEndpointsDisabled();
    $fake->assertEndpointNotDisabled($out);
    fails(fn () => $fake->assertEndpointDisabled($out), 'to be disabled');

    Webhooks::for($team)->outbound()->disable($out);
    Webhooks::inbound()->disable($in);
    Webhooks::inbound()->enable($in);

    $fake->assertEndpointDisabled($out);
    $fake->assertEndpointDisabled($in);
    expect($out->refresh()->enabled)->toBeFalse()->and($in->refresh()->enabled)->toBeTrue();
    fails(fn () => $fake->assertEndpointNotDisabled($out), 'not to be disabled');
    fails(fn () => $fake->assertNoEndpointsDisabled(), 'Expected no endpoint to be disabled, but 2 were.');
    expect(Webhooks::outbound()->dispatchDue())->toBe(0);
});

it('fake_survives_or_documents_scope_reset', function (): void {
    $fake = Webhooks::fake();

    // What a queue worker does before every job (Octane between requests).
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    expect(Webhooks::getFacadeRoot())->toBe($fake)
        ->and(app(WebhooksManager::class))->toBe($fake);

    Webhooks::send(outboundEndpoint(), 'invoice.paid', []);
    $fake->assertSent('invoice.paid');
});
