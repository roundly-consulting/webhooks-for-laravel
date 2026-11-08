<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Sentinel\Enums\Algorithm;
use RoundlyConsulting\Sentinel\Facades\Sentinel;
use RoundlyConsulting\Sentinel\Keys\KeyStoreManager;
use RoundlyConsulting\Webhooks\Actions\Outbound\DisableOutboundEndpointAction;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Events\EndpointDisabled;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Webhooks\Tests\Support\Racer;

const RACE_PARTNER_KEY = 'base64:cGFydG5lci1zaGFyZWQtc2VjcmV0LTMyLWJ5dGVzLWxvbmctZm9yLWhtYWM=';

/**
 * Two processes on a real engine (pgsql / mysql legs): the row lock keeps concurrent rotations
 * consistent, and the conditional update fires `EndpointDisabled` once. The parent holds the row
 * while a forked racer runs into it, then commits its own change first.
 */
beforeEach(function (): void {
    if (! Racer::available()) {
        $this->markTestSkipped('needs pgsql or mysql and pcntl');
    }
});

it('two_processes_rotating_keep_a_consistent_pair', function (): void {
    $endpoint = outboundEndpoint();
    $original = $endpoint->secret;
    $racer = new Racer;

    DB::beginTransaction();
    OutboundEndpoint::query()->whereKey($endpoint->getKey())->lockForUpdate()->first();

    $racer->run(static fn (): string => Webhooks::outbound()->rotateSecret(OutboundEndpoint::query()->findOrFail($endpoint->getKey()))->secret)
        ->waitUntilBlockedOrDone();

    $mine = Webhooks::outbound()->rotateSecret($endpoint)->secret;
    DB::commit();

    $theirs = $racer->outcome();
    $row = OutboundEndpoint::query()->findOrFail($endpoint->getKey());

    // The racer rotated after us: it kept our secret as the previous one, never the original.
    expect($theirs)->toStartWith('whsec_')
        ->and($row->secret)->toBe($theirs)
        ->and($row->previous_secret)->toBe($mine)
        ->and($row->previous_secret)->not->toBe($original);
});

it('two_processes_disabling_fire_one_event', function (): void {
    Event::fake([EndpointDisabled::class]);
    $endpoint = outboundEndpoint(attributes: ['consecutive_failures' => 5]);
    $racer = new Racer;

    DB::beginTransaction();
    OutboundEndpoint::query()->whereKey($endpoint->getKey())->lockForUpdate()->first();

    $racer->run(static fn (): string => app(DisableOutboundEndpointAction::class)
        ->execute(OutboundEndpoint::query()->findOrFail($endpoint->getKey()), DisableReason::ConsecutiveFailures, 5) ? 'disabled' : 'skipped')
        ->waitUntilBlockedOrDone();

    $mine = app(DisableOutboundEndpointAction::class)->execute($endpoint, DisableReason::ConsecutiveFailures, 5);
    DB::commit();

    expect($mine)->toBeTrue()
        ->and($racer->outcome())->toBe('skipped')
        ->and($endpoint->refresh()->enabled)->toBeFalse();
    Event::assertDispatchedTimes(EndpointDisabled::class, 1);
});

it('two_processes_resending_one_rfc9421_request_store_it_once', function (string $secondId, int $status): void {
    Queue::fake();
    config()->set('sentinel.keys.rings.partner', ['driver' => 'config', 'key_id' => 'partner', 'algorithm' => 'hmac-sha256', 'key' => RACE_PARTNER_KEY, 'algorithms' => ['hmac-sha256']]);
    config()->set('sentinel.signatures.outbound.ring', 'partner');
    config()->set('sentinel.keys.rings.http.driver', 'database');
    app(KeyStoreManager::class)->flush();
    $endpoint = Webhooks::inbound()->create(scheme: 'rfc9421', options: new InboundEndpointOptions(schemeOptions: ['id_header' => 'x-delivery']))->endpoint;
    Sentinel::keys()->ring('http')->import('partner', Algorithm::HmacSha256, RACE_PARTNER_KEY, owner: $endpoint);
    $signed = SignedWebhookRequest::for($endpoint)->keyId('partner')->withHeader('X-Delivery', 'd-1')->toRequest();
    $post = static function (string $id) use ($signed): int {
        $request = Request::create($signed->uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $signed->body);

        foreach ([...$signed->headers, 'x-delivery' => $id] as $name => $value) {
            $request->headers->set($name, $value);
        }

        return app(Kernel::class)->handle($request)->getStatusCode();
    };
    $racer = new Racer;

    // This copy is stored and its nonce committed, but not yet visible to anyone else…
    DB::beginTransaction();
    expect($post('d-1'))->toBe(202);

    // …while a second copy of the same signed request (the same nonce) runs into it.
    $racer->run(static fn (): string => (string) $post($secondId))->waitUntilBlockedOrDone();
    DB::commit();

    // Same id: a duplicate of the stored delivery. Another (unsigned) id: the nonce is used — a replay.
    expect($racer->outcome())->toBe((string) $status)
        ->and(InboundDelivery::query()->pluck('idempotency_key')->all())->toBe(['d-1']);
})->with([
    'the same delivery id' => ['d-1', 200],
    'another delivery id' => ['d-2', 401],
]);
