<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Crypto\Codec\InvalidEncodingException;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\Contracts\SignsOutboundWebhooks;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutgoingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\SignatureHeaders;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;

/**
 * The docs recipe: `Stripe-Signature: t=<ts>,v1=<hex HMAC("t.body")>`. The key is the whole
 * `whsec_…` string as raw bytes — `SecretEncoding::Raw`, never base64-decoded. The event id sits
 * inside the signed body, so it is a signed id. `sign()` is optional (outbound use and
 * `SignedWebhookRequest`).
 */
final readonly class StripeScheme implements SignatureScheme, SignsOutboundWebhooks
{
    public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
    {
        $header = $request->header('stripe-signature') ?? throw WebhookSignatureException::because(RejectionReason::MissingSignature);
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? '';

        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            throw WebhookSignatureException::because(RejectionReason::InvalidTimestamp);
        }

        if (abs($request->receivedAt->getTimestamp() - (int) $timestamp) > $request->toleranceSeconds) {
            throw WebhookSignatureException::because(RejectionReason::StaleTimestamp);
        }

        foreach ($parts['v1'] ?? [] as $hex) {
            try {
                $slot = $secrets->matches($timestamp.'.'.$request->body, Crypto::hexDecode($hex), SecretEncoding::Raw);
            } catch (InvalidEncodingException) {
                continue;
            }

            if ($slot !== null) {
                $event = json_decode($request->body, true);
                $id = is_array($event) ? ($event['id'] ?? null) : null;

                return new VerifiedWebhook(is_string($id) ? $id : null, CarbonImmutable::createFromTimestampUTC((int) $timestamp), null, true, $slot);
            }
        }

        throw WebhookSignatureException::because(RejectionReason::InvalidSignature);
    }

    public function sign(OutgoingWebhook $message, EndpointSecrets $secrets): SignatureHeaders
    {
        $timestamp = (string) $message->timestamp->getTimestamp();
        $signatures = array_map(static fn (string $mac): string => 'v1='.Crypto::hexEncode($mac), $secrets->sign($timestamp.'.'.$message->body, SecretEncoding::Raw));

        return new SignatureHeaders(['Stripe-Signature' => 't='.$timestamp.','.implode(',', $signatures)]);
    }
}
