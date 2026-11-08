<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * A delivery ended `failed`: count it (atomically) and disable the endpoint once
 * `outbound.disable_after` deliveries in a row have failed. A success in between resets the
 * counter and wins.
 *
 * @internal
 */
final readonly class RecordEndpointFailureAction
{
    public function __construct(private DisableOutboundEndpointAction $disable) {}

    public function execute(OutboundEndpoint $endpoint): void
    {
        $now = Clock::format(Clock::now());

        ModelSeams::outboundEndpoint()::query()->whereKey($endpoint->getKey())->update(['last_failure_at' => $now]);
        ModelSeams::outboundEndpoint()::query()->whereKey($endpoint->getKey())->increment('consecutive_failures');

        $this->disable->execute($endpoint, DisableReason::ConsecutiveFailures, WebhooksConfig::disableAfter());
    }
}
