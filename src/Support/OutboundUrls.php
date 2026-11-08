<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\Webhooks\DataTransferObjects\JudgedUrl;
use RoundlyConsulting\Webhooks\Exceptions\DestinationBlockedException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use SensitiveParameter;

/**
 * Judges an outbound endpoint URL when it is saved: every rule plus the DNS lookup
 * (`outbound.verify_on_save`), or every rule except the lookup. Every attempt is judged again in
 * full — DNS can change. Returns the canonical URL to store.
 *
 * @internal
 */
final readonly class OutboundUrls
{
    public function __construct(
        private SafeFetchManager $safeFetch,
        private DestinationPolicyFactory $policies,
    ) {}

    /**
     * @throws DestinationBlockedException|InvalidEndpointException
     */
    public function judge(#[SensitiveParameter] string $url): JudgedUrl
    {
        if (strlen($url) > 2048) {
            throw InvalidEndpointException::url();
        }

        if (! WebhooksConfig::ssrfEnabled()) {
            return $this->shapeOnly($url);
        }

        $policy = $this->policies->make();

        try {
            if (WebhooksConfig::verifyOnSave()) {
                $vetted = $this->safeFetch->vet($url, $policy);

                return new JudgedUrl($vetted->url, $vetted->host);
            }

            $canonical = $this->safeFetch->precheck($url, $policy);
        } catch (SafeFetchException $exception) {
            throw DestinationBlockedException::from($exception);
        }

        return new JudgedUrl($canonical->toString(), $canonical->host);
    }

    /**
     * With the SSRF guard off: the canonical shape and the scheme only.
     */
    private function shapeOnly(string $url): JudgedUrl
    {
        try {
            $canonical = $this->safeFetch->urls()->canonicalise($url);
        } catch (SafeFetchException) {
            throw InvalidEndpointException::url();
        }

        if (! self::schemeAllowed($canonical)) {
            throw InvalidEndpointException::url();
        }

        return new JudgedUrl($canonical->toString(), $canonical->host);
    }

    private static function schemeAllowed(CanonicalUrl $url): bool
    {
        return $url->scheme === 'https' || ($url->scheme === 'http' && WebhooksConfig::allowHttp());
    }
}
