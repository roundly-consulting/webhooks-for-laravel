<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\Queues;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Put a failed delivery back for its next attempt: a short delay is a delayed job, a long one
 * (beyond `queue.max_delay`, SQS' 900 s) waits for the `webhooks:retry` sweep.
 *
 * @internal
 */
final readonly class ScheduleRetryAction
{
    public function __construct(private Queues $queues) {}

    public function execute(OutboundDelivery $delivery, int $delaySeconds): CarbonImmutable
    {
        $next = Clock::now()->addSeconds($delaySeconds);

        $delivery->forceFill([
            'status' => OutboundStatus::Retrying,
            'next_attempt_at' => $next,
            'claimed_until' => null,
            'dispatch_token' => null,
        ])->save();

        if ($delaySeconds <= WebhooksConfig::maxDelay()) {
            $this->queues->outbound($delivery, $delaySeconds);
        }

        return $next;
    }
}
