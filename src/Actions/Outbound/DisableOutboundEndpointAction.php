<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Events\EndpointDisabled;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;

/**
 * Disable an outbound endpoint exactly once: one conditional `UPDATE … WHERE disabled_at IS NULL`
 * affects the row for one caller only, and only that caller fires `EndpointDisabled` and cancels
 * the endpoint's pending deliveries — racing workers cannot fire it twice.
 *
 * @internal
 */
final readonly class DisableOutboundEndpointAction
{
    public function __construct(
        private CancelPendingDeliveriesAction $cancel,
        private Dispatcher $events,
    ) {}

    /**
     * @param  int|null  $minFailures  only when the endpoint has failed at least this many times in a row
     */
    public function execute(OutboundEndpoint $endpoint, DisableReason $reason, ?int $minFailures = null): bool
    {
        $now = Clock::now();
        $query = ModelSeams::outboundEndpoint()::query()->whereKey($endpoint->getKey())->whereNull('disabled_at');

        if ($minFailures !== null) {
            $query->where('consecutive_failures', '>=', $minFailures);
        }

        $affected = $query->update([
            'enabled' => false,
            'disabled_at' => Clock::format($now),
            'disabled_reason' => $reason->value,
            'updated_at' => Clock::format($now),
        ]);

        $endpoint->refresh();

        if ($affected !== 1) {
            return false;
        }

        $this->cancel->execute($endpoint);
        $this->events->dispatch(new EndpointDisabled($endpoint, $reason));

        return true;
    }
}
