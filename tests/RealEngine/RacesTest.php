<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Webhooks\Actions\Outbound\DisableOutboundEndpointAction;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Events\EndpointDisabled;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Tests\Support\Racer;

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
