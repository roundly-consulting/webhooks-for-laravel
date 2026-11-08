<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Contracts;

use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;

/**
 * A signature format for inbound webhooks. The scheme never sees a secret: it asks
 * `$secrets->matches()` whether a MAC it computed the message for is valid.
 */
interface SignatureScheme
{
    /**
     * @throws WebhookSignatureException with the rejection reason
     */
    public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook;
}
