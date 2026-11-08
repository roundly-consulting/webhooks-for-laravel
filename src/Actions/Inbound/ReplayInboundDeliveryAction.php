<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Support\Queues;

/**
 * Run an inbound delivery's handler again with its stored payload, flagged as a replay (the next
 * run number).
 */
final readonly class ReplayInboundDeliveryAction
{
    public function __construct(private Queues $queues) {}

    /**
     * @throws PayloadPrunedException|DeliveryInProgressException
     */
    public function execute(InboundDelivery $delivery): InboundDelivery
    {
        if ($delivery->payload_pruned_at !== null || $delivery->payload === null) {
            throw PayloadPrunedException::make();
        }

        if ($delivery->status === InboundStatus::Processing) {
            throw DeliveryInProgressException::make();
        }

        $delivery->forceFill(['runs' => $delivery->runs + 1, 'status' => InboundStatus::Queued])->save();
        $this->queues->inbound($delivery, replay: true);

        return $delivery->refresh();
    }
}
