<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\RedirectPolicy;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;

/**
 * The destination policy every outbound endpoint is judged by: the host's safe-fetch policy with
 * its blocks kept and its carve-outs (private hosts, allowed ranges, a host allowlist) dropped —
 * those were set up for the host's own fetches, not tenant endpoints — then webhooks' own
 * `ssrf.*` / `outbound.*` settings.
 *
 * @internal
 */
final readonly class DestinationPolicyFactory
{
    public function __construct(private SafeFetchManager $safeFetch) {}

    public function make(): DestinationPolicy
    {
        $policy = $this->safeFetch->policy()
            ->withoutHostAllowlist()->withoutPrivateHosts()->withoutAllowedRanges()
            ->blockAppHosts(WebhooksConfig::blockAppHosts())
            ->appHosts(WebhooksConfig::appHosts())
            ->blockHosts(WebhooksConfig::blockedHosts())
            ->blockRanges(WebhooksConfig::blockedRanges())
            ->allowRanges(WebhooksConfig::allowedRanges())
            ->ports(WebhooksConfig::allowedPorts())
            ->maxResponseBytes(WebhooksConfig::maxResponseBytes())
            ->redirects(WebhooksConfig::followRedirects()
                ? RedirectPolicy::methodPreserving(WebhooksConfig::maxRedirects(), forwardHeaders: [DefaultScheme::ID, DefaultScheme::TIMESTAMP, DefaultScheme::SIGNATURE])
                : RedirectPolicy::none());

        $policy = WebhooksConfig::allowHttp() ? $policy->allowHttp() : $policy->httpsOnly();
        $allowed = WebhooksConfig::allowedHosts();

        return $allowed === [] ? $policy : $policy->allowHosts($allowed);
    }
}
