<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Crypto\Codec\InvalidEncodingException;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\DataTransferObjects\IncomingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;

/**
 * The docs recipe: `Stripe-Signature: t=<ts>,v1=<hex HMAC("t.body")>`. The key is the whole
 * `whsec_…` string as raw bytes — `SecretEncoding::Raw`, never base64-decoded.
 */
final readonly class StripeScheme implements SignatureScheme
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
                $id = json_decode($request->body, true)['id'] ?? null;

                return new VerifiedWebhook(is_string($id) ? $id : null, CarbonImmutable::createFromTimestampUTC((int) $timestamp), null, false, $slot);
            }
        }

        throw WebhookSignatureException::because(RejectionReason::InvalidSignature);
    }
}
