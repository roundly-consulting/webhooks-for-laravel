<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Contracts;

use RoundlyConsulting\Webhooks\DataTransferObjects\OutgoingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\SignatureHeaders;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;

/**
 * A scheme that can also sign outbound deliveries.
 */
interface SignsOutboundWebhooks
{
    public function sign(OutgoingWebhook $message, EndpointSecrets $secrets): SignatureHeaders;
}
