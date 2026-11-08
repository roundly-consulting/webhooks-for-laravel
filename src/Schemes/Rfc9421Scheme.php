<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Schemes;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use RoundlyConsulting\Sentinel\DataTransferObjects\SigningOptions;
use RoundlyConsulting\Sentinel\Enums\SignatureRejection;
use RoundlyConsulting\Sentinel\Exceptions\HttpSignatureException;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * RFC 9421 HTTP message signatures, verified by sentinel against a signature profile (its
 * `max_age` / `clock_skew` replace the tolerance, its nonce store the replay check). The nonce is
 * verified unused but recorded only through the verdict's `commit` — the pipeline commits once
 * the delivery is stored, so a request refused after verification can be resent as is. The key
 * must be imported into sentinel bound to **this** endpoint:
 *
 *     Sentinel::keys()->ring('http')->import($kid, Algorithm::Ed25519, $pem, owner: $endpoint);
 *
 * The delivery id comes from the `id_header` scheme option (default `webhook-id`); it counts as
 * signed only when the sender's signature covers that header.
 */
final readonly class Rfc9421Scheme implements SignatureScheme
{
    public const string DEFAULT_ID_HEADER = 'webhook-id';

    /** `@query` is signed only when the URL has one (sentinel drops it otherwise), as its default profile requires. */
    public const array OUTBOUND_COMPONENTS = ['@method', '@authority', '@path', '@query', 'content-digest', 'content-type', 'webhook-id', 'webhook-timestamp'];

    public function __construct(private SentinelManager $sentinel) {}

    public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
    {
        if ($request->request === null) {
            throw WebhookSignatureException::because(RejectionReason::MissingSignature);
        }

        try {
            $deferred = $this->sentinel->signatures()->verifyDeferred($request->request, self::option($request->schemeOptions, 'profile') ?? WebhooksConfig::rfc9421Profile());
        } catch (HttpSignatureException $exception) {
            throw self::rejected($exception);
        }

        $verified = $deferred->signature;
        $owner = $this->sentinel->signatures()->owner($verified);

        if (! $owner instanceof InboundEndpoint || $owner->url_key !== $request->endpointKey) {
            throw WebhookSignatureException::because(RejectionReason::KeyNotBound);
        }

        $idHeader = strtolower(self::option($request->schemeOptions, 'id_header') ?? self::DEFAULT_ID_HEADER);
        $id = $request->header($idHeader);
        $signed = in_array($idHeader, array_map(strtolower(...), $verified->components), true);

        if (($id === null || $id === '') && $request->header('idempotency-key') === null && $verified->nonce !== null) {
            // No id header and no Idempotency-Key: the signed nonce is the delivery id.
            [$id, $signed] = ['nonce:'.$verified->nonce, true];
        }

        return new VerifiedWebhook(
            $id === '' ? null : $id,
            CarbonImmutable::createFromTimestampUTC($verified->created),
            null,
            $signed,
            SecretSlot::Current,
            // Once committed, sentinel remembers the nonce: this exact request never verifies again.
            replayProtected: $verified->nonce !== null,
            commit: function () use ($deferred): void {
                try {
                    $this->sentinel->signatures()->commitNonce($deferred);
                } catch (HttpSignatureException $exception) {
                    throw self::rejected($exception);
                }
            },
        );
    }

    private static function rejected(HttpSignatureException $exception): WebhookSignatureException
    {
        return WebhookSignatureException::because(match ($exception->reason()) {
            SignatureRejection::Missing => RejectionReason::MissingSignature,
            SignatureRejection::Replayed => RejectionReason::Replayed,
            SignatureRejection::TooOld, SignatureRejection::NotYetValid, SignatureRejection::Expired => RejectionReason::StaleTimestamp,
            default => RejectionReason::InvalidSignature,
        });
    }

    /**
     * Sign an outbound request with a sentinel key (re-signed on every redirect hop).
     */
    public function prepare(PendingRequest $request, string $keyId): PendingRequest
    {
        return $request->withSignature($keyId, new SigningOptions(components: self::OUTBOUND_COMPONENTS));
    }

    /**
     * @param  array<string, string>  $options
     */
    private static function option(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
