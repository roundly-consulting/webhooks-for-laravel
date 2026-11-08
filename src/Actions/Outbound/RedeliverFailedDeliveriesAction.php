<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Webhooks\DataTransferObjects\RedeliveryResult;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;

/**
 * After an outage: send every failed delivery of an endpoint again (created since `$since`, and
 * the ones its disable cancelled when `$includeCancelled`). Each one is a redelivery — same
 * `webhook-id`, a new series of attempts. Chunked; a delivery whose body retention already
 * cleared is skipped and counted. A disabled or deleted endpoint is refused: enable it first.
 */
final readonly class RedeliverFailedDeliveriesAction
{
    private const int CHUNK = 200;

    public function __construct(private RedeliverWebhookAction $redeliver) {}

    /**
     * @throws EndpointNotAcceptingException
     */
    public function execute(OutboundEndpoint $endpoint, ?CarbonInterface $since = null, bool $includeCancelled = false): RedeliveryResult
    {
        if (! $endpoint->enabled || $endpoint->trashed()) {
            throw EndpointNotAcceptingException::disabled();
        }

        $queued = 0;
        $skipped = 0;

        self::candidates($endpoint, $since, $includeCancelled)->chunkById(self::CHUNK, function ($deliveries) use ($endpoint, &$queued, &$skipped): void {
            foreach ($deliveries as $delivery) {
                if (self::pruned($delivery)) {
                    $skipped++;

                    continue;
                }

                $delivery->setRelation('endpoint', $endpoint);
                $this->redeliver->execute($delivery);
                $queued++;
            }
        });

        return new RedeliveryResult($queued, $skipped);
    }

    /**
     * The deliveries a bulk redelivery takes — shared with `Webhooks::fake()`, which counts them
     * without queueing anything.
     *
     * @return Builder<OutboundDelivery>
     */
    public static function candidates(OutboundEndpoint $endpoint, ?CarbonInterface $since, bool $includeCancelled): Builder
    {
        $statuses = $includeCancelled
            ? [OutboundStatus::Failed->value, OutboundStatus::Cancelled->value]
            : [OutboundStatus::Failed->value];

        $query = ModelSeams::outboundDelivery()::query()
            ->where('endpoint_id', $endpoint->getKey())
            ->whereIn('status', $statuses);

        return $since === null ? $query : $query->where('created_at', '>=', Clock::format(Clock::utc($since)));
    }

    public static function pruned(OutboundDelivery $delivery): bool
    {
        return $delivery->payload_pruned_at !== null || $delivery->body === null;
    }
}
