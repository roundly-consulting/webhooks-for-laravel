<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\Queues;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use Throwable;

/**
 * Run an inbound delivery's handler again with its stored payload, flagged as a replay (the next
 * run number). A run still in progress is refused — unless it has been `processing` longer than
 * a handler attempt can take (its worker died); a job that cannot be queued leaves the delivery
 * as it was.
 */
final readonly class ReplayInboundDeliveryAction
{
    /** Seconds on top of the handler's time budget before a `processing` run counts as lost. */
    private const int LOST_AFTER_SLACK = 90;

    public function __construct(private Queues $queues) {}

    /**
     * @throws PayloadPrunedException|DeliveryInProgressException
     */
    public function execute(InboundDelivery $delivery): InboundDelivery
    {
        self::assertReplayable($delivery);

        $before = ['runs' => $delivery->runs, 'status' => $delivery->status, 'queued_at' => $delivery->queued_at];
        $delivery->forceFill(['runs' => $delivery->runs + 1, 'status' => InboundStatus::Queued, 'queued_at' => Clock::now()])->save();

        try {
            $this->queues->inbound($delivery, replay: true);
        } catch (Throwable $exception) {
            // Never stranded in `queued` with no job behind it.
            $delivery->forceFill($before)->save();

            throw $exception;
        }

        return $delivery->refresh();
    }

    /**
     * The checks a replay makes before queuing anything (shared with `Webhooks::fake()`): a body
     * still stored, and no run in progress — a `processing` run past its budget counts as lost.
     *
     * @throws PayloadPrunedException|DeliveryInProgressException
     */
    public static function assertReplayable(InboundDelivery $delivery): void
    {
        if ($delivery->payload_pruned_at !== null || $delivery->payload === null) {
            throw PayloadPrunedException::make();
        }

        if ($delivery->status === InboundStatus::Processing && ! self::lost($delivery)) {
            throw DeliveryInProgressException::make();
        }
    }

    /**
     * Its last attempt started longer ago than an attempt, its lease and the longest
     * backoff together can take.
     */
    private static function lost(InboundDelivery $delivery): bool
    {
        $started = $delivery->processing_started_at;
        $backoff = WebhooksConfig::handlerBackoff();
        $budget = WebhooksConfig::handlerTimeout() + 30 + ($backoff === [] ? 0 : max($backoff)) + self::LOST_AFTER_SLACK;

        return $started !== null && $started->addSeconds($budget)->isPast();
    }
}
