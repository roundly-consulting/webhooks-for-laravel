<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Queues;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Send a finished delivery again: a new series of attempts with the same `webhook-id` (receivers
 * dedupe on it), each attempt flagged manual. A delivery still in flight, a pruned body or a
 * disabled endpoint is refused.
 */
final readonly class RedeliverWebhookAction
{
    public function __construct(private Queues $queues) {}

    /**
     * @throws DeliveryInProgressException|PayloadPrunedException|EndpointNotAcceptingException
     */
    public function execute(OutboundDelivery $delivery): OutboundDelivery
    {
        self::assertRedeliverable($delivery);

        $delivery->forceFill([
            'status' => OutboundStatus::Pending,
            'series' => $delivery->series + 1,
            'attempts' => 0,
            'max_attempts' => WebhooksConfig::maxAttempts(),
            'next_attempt_at' => null,
            'claimed_until' => null,
            'succeeded_at' => null,
            'failed_at' => null,
        ])->save();

        $this->queues->outbound($delivery);

        return $delivery;
    }

    /**
     * @throws DeliveryInProgressException|PayloadPrunedException|EndpointNotAcceptingException
     */
    public static function assertRedeliverable(OutboundDelivery $delivery): void
    {
        if (in_array($delivery->status, [OutboundStatus::Pending, OutboundStatus::Sending, OutboundStatus::Retrying], true)) {
            throw DeliveryInProgressException::make();
        }

        if ($delivery->payload_pruned_at !== null || $delivery->body === null) {
            throw PayloadPrunedException::make();
        }

        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof OutboundEndpoint || ! $endpoint->enabled || $endpoint->trashed()) {
            throw EndpointNotAcceptingException::disabled();
        }
    }
}
