<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\Queues;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * The sweep `webhooks:retry` runs every minute:
 *
 *  - outbound deliveries that are due and have no live job — parked (throttled), waiting longer
 *    than the queue can delay, or whose job is late by more than `outbound.requeue_after` (lost);
 *  - attempts whose worker died (an expired claim): recorded as `worker_lost`, then retried;
 *  - inbound deliveries stored but never queued.
 *
 * A row whose short-delay job is merely a few seconds late is left alone. Returns how many jobs
 * were dispatched.
 */
final readonly class DispatchDueDeliveriesAction
{
    private const int CHUNK = 200;

    public function __construct(private Queues $queues) {}

    public function execute(): int
    {
        $this->recoverLostAttempts();

        return $this->dispatchOutbound() + $this->requeueInbound();
    }

    private function dispatchOutbound(): int
    {
        $now = Clock::now();
        $stale = Clock::format($now->subSeconds(WebhooksConfig::outboundRequeueAfter()));
        $count = 0;

        ModelSeams::outboundDelivery()::query()
            ->whereIn('status', [OutboundStatus::Pending->value, OutboundStatus::Retrying->value])
            ->where(static fn (Builder $query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<', Clock::format($now)))
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $due) => $due->whereNull('dispatch_token')->where(static fn (Builder $at) => $at->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Clock::format($now))))
                ->orWhere(static fn (Builder $lost) => $lost->whereNotNull('dispatch_token')
                    ->where('dispatched_at', '<=', $stale)
                    ->where(static fn (Builder $at) => $at->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $stale))))
            ->chunkById(self::CHUNK, function ($deliveries) use (&$count): void {
                foreach ($deliveries as $delivery) {
                    $this->queues->outbound($delivery);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * A `sending` row whose claim expired: the worker died mid-attempt.
     */
    private function recoverLostAttempts(): void
    {
        $now = Clock::now();

        ModelSeams::outboundDelivery()::query()
            ->where('status', OutboundStatus::Sending->value)
            ->where('claimed_until', '<', Clock::format($now))
            ->chunkById(self::CHUNK, static function ($deliveries) use ($now): void {
                foreach ($deliveries as $delivery) {
                    $number = $delivery->attempts + 1;
                    $attempt = ModelSeams::outboundAttempt();

                    (new $attempt)->forceFill([
                        'delivery_id' => $delivery->getKey(),
                        'series' => $delivery->series,
                        'number' => $number,
                        'manual' => $delivery->series > 1,
                        'started_at' => $delivery->claimed_until ?? $now,
                        'error_code' => AttemptError::WorkerLost,
                        'error_message' => AttemptError::WorkerLost->value,
                    ])->save();

                    $final = $number >= $delivery->max_attempts;

                    $delivery->forceFill([
                        'attempts' => $number,
                        'status' => $final ? OutboundStatus::Failed : OutboundStatus::Retrying,
                        'failed_at' => $final ? $now : null,
                        'last_error_code' => AttemptError::WorkerLost,
                        'last_error_message' => AttemptError::WorkerLost->value,
                        'claimed_until' => null,
                        'dispatch_token' => null,
                        'next_attempt_at' => $final ? null : $now,
                    ])->save();
                }
            });
    }

    private function requeueInbound(): int
    {
        $before = Clock::format(Clock::now()->subSeconds(WebhooksConfig::inboundRequeueAfter()));
        $count = 0;

        ModelSeams::inboundDelivery()::query()
            ->where('status', InboundStatus::Received->value)
            ->whereNull('queued_at')
            ->where('received_at', '<=', $before)
            ->chunkById(self::CHUNK, function ($deliveries) use (&$count): void {
                foreach ($deliveries as $delivery) {
                    $this->queues->inbound($delivery);
                    // Conditional: on a sync queue the job already moved the status on.
                    $query = ModelSeams::inboundDelivery()::query()->whereKey($delivery->getKey());
                    (clone $query)->update(['queued_at' => Clock::format(Clock::now())]);
                    $query->where('status', InboundStatus::Received->value)->update(['status' => InboundStatus::Queued->value]);
                    $count++;
                }
            });

        return $count;
    }
}
