<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Controllers;

use Illuminate\Http\Request;
use RoundlyConsulting\Webhooks\WebhooksManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST {prefix}/{key}` — hands the request to the inbound pipeline through the manager.
 */
final readonly class ReceiveWebhookController
{
    public function __construct(private WebhooksManager $webhooks) {}

    public function __invoke(Request $request, string $key): Response
    {
        return $this->webhooks->inbound()->receive($key, $request);
    }
}
