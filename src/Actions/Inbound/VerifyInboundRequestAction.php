<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Verify a request's signature and timestamp with the endpoint's scheme — and nothing else: no
 * dedupe, no size check, nothing stored. What the pipeline's signature step runs, and what
 * `Webhooks::inbound()->verify()` exposes for a host that mounts its own flow.
 */
final readonly class VerifyInboundRequestAction
{
    public function __construct(
        private SchemeRegistry $schemes,
        private Container $container,
    ) {}

    /**
     * The request's single use (an RFC 9421 nonce) is recorded here — unless `$deferCommit`: then
     * the verdict's `commit` is the caller's to run (the pipeline does, once the delivery is
     * stored).
     *
     * @param  string|null  $body  the raw body already read (null: read it from the request)
     *
     * @throws WebhookSignatureException
     */
    public function execute(InboundEndpoint $endpoint, Request $request, ?string $body = null, bool $deferCommit = false): VerifiedWebhook
    {
        try {
            $scheme = $this->schemes->resolve($endpoint->signature_scheme);
        } catch (UnknownSchemeException) {
            throw WebhookSignatureException::because(RejectionReason::UnknownScheme);
        }

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $first = $values[0] ?? null;

            if (is_string($first)) {
                $headers[strtolower($name)] = $first;
            }
        }

        /** @var array<string, string> $options */
        $options = $endpoint->scheme_options ?? [];

        $incoming = new IncomingWebhook(
            $headers,
            $body ?? $request->getContent(),
            Clock::now(),
            $endpoint->url_key,
            $endpoint->tolerance_seconds ?? WebhooksConfig::tolerance(),
            $options,
            $request,
        );

        $verified = $scheme->verify($incoming, new EndpointSecrets($endpoint->activeSecrets(), $this->container->make(MacEngine::class)));

        return $deferCommit ? $verified : $verified->committed();
    }
}
