<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonSerializable;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\EventTypes;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OwnerEndpoints;

/**
 * Send an event to every enabled endpoint of an owner that subscribes to it (no owner: the
 * owner-less, application-level endpoints only). Endpoints that do not take it are skipped.
 */
final readonly class BroadcastWebhookAction
{
    public function __construct(private SendWebhookAction $send) {}

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     * @return Collection<int, OutboundDelivery>
     *
     * @throws InvalidEventTypeException|PayloadTooLargeException
     */
    public function execute(string $eventType, array|Arrayable|JsonSerializable $payload, ?Model $owner = null): Collection
    {
        EventTypes::assertSendable($eventType);

        $deliveries = new Collection;

        foreach (self::targets($eventType, $owner) as $endpoint) {
            $deliveries->push($this->send->execute($endpoint, $eventType, $payload));
        }

        return $deliveries;
    }

    /**
     * @return Collection<int, OutboundEndpoint>
     */
    public static function targets(string $eventType, ?Model $owner): Collection
    {
        return OwnerEndpoints::scope(ModelSeams::outboundEndpoint()::query(), $owner)
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->filter(static fn (OutboundEndpoint $endpoint): bool => $endpoint->subscribesTo($eventType))
            ->values();
    }
}
