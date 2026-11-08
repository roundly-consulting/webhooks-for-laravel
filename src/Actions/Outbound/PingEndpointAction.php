<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\OutboundDeliveries;
use RoundlyConsulting\Webhooks\Support\OutboundMessages;

/**
 * Send a `webhook.ping` test event to an endpoint — whatever it subscribes to, and whether or not
 * `outbound.event_types` lists the type — so a customer can check their receiver. A normal
 * delivery otherwise: signed, retried, logged. A disabled endpoint is refused.
 */
final readonly class PingEndpointAction
{
    public const string EVENT_TYPE = 'webhook.ping';

    public function __construct(private OutboundDeliveries $deliveries) {}

    /**
     * @throws EndpointNotAcceptingException
     */
    public function execute(OutboundEndpoint $endpoint): OutboundDelivery
    {
        if (! $endpoint->enabled || $endpoint->trashed()) {
            throw EndpointNotAcceptingException::disabled();
        }

        return $this->deliveries->store($endpoint, self::EVENT_TYPE, OutboundMessages::encode(self::EVENT_TYPE, ['ping' => true]));
    }
}
