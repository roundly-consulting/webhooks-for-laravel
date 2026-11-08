<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * On the real sync queue (the TestCase default): after-commit dispatch and job middleware.
 */
beforeEach(fn () => publicDns());

it('a_rolled_back_send_dispatches_nothing', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $endpoint = outboundEndpoint();

    try {
        DB::transaction(function () use ($endpoint): void {
            Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 1]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(OutboundDelivery::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('runs a queued job through its middleware exactly once', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 1]);

    expect($delivery->refresh()->status)->toBe(OutboundStatus::Succeeded);
    Http::assertSentCount(1);

    // Dispatching the same job again: sentinel's idempotent middleware skips it.
    dispatch(new DeliverWebhook($delivery->getKey(), 'already-used'));
    Http::assertSentCount(1);
});
