<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Schemes;

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

/**
 * The package's own scheme — Standard Webhooks v1 compatible:
 *
 *     signed_content = webhook-id + "." + webhook-timestamp + "." + body
 *     signature      = "v1," + base64(HMAC-SHA256(key, signed_content))
 *
 * `key` is the base64-decoded secret after `whsec_` (or a non-`whsec_` secret's own bytes).
 * `webhook-signature` carries one or more space-separated `v1,` entries (both secrets during a
 * rotation grace); unknown versions are ignored.
 */
final readonly class DefaultScheme implements SignatureScheme, SignsOutboundWebhooks
{
    public const string ID = 'webhook-id';

    public const string TIMESTAMP = 'webhook-timestamp';

    public const string SIGNATURE = 'webhook-signature';

    /** The most `v1,` entries read, and the longest header — bounds the work per request. */
    public const int MAX_ENTRIES = 8;

    public const int MAX_HEADER_BYTES = 4096;

    public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
    {
        $signature = $request->header(self::SIGNATURE);
        $timestamp = $request->header(self::TIMESTAMP);
        $id = $request->header(self::ID);

        if ($signature === null || trim($signature) === '') {
            throw WebhookSignatureException::because(RejectionReason::MissingSignature);
        }

        if ($timestamp === null || $timestamp === '') {
            throw WebhookSignatureException::because(RejectionReason::MissingTimestamp);
        }

        if ($id === null || $id === '') {
            throw WebhookSignatureException::because(RejectionReason::MissingId);
        }

        if (preg_match('/^[0-9]{1,12}$/D', $timestamp) !== 1) {
            throw WebhookSignatureException::because(RejectionReason::InvalidTimestamp);
        }

        $signedAt = CarbonImmutable::createFromTimestampUTC((int) $timestamp);

        if (abs($request->receivedAt->getTimestamp() - $signedAt->getTimestamp()) > $request->toleranceSeconds) {
            throw WebhookSignatureException::because(RejectionReason::StaleTimestamp);
        }

        // Any visible ASCII (the spec does not narrow the id; the MAC covers it either way).
        if (preg_match('/^[\x21-\x7e]{1,255}$/D', $id) !== 1 || strlen($signature) > self::MAX_HEADER_BYTES) {
            throw WebhookSignatureException::because(RejectionReason::InvalidSignature);
        }

        $entries = array_values(array_filter(explode(' ', $signature), static fn (string $entry): bool => $entry !== ''));

        if (count($entries) > self::MAX_ENTRIES) {
            throw WebhookSignatureException::because(RejectionReason::InvalidSignature);
        }

        $message = self::signedContent($id, $timestamp, $request->body);

        foreach ($entries as $entry) {
            if (! str_starts_with($entry, 'v1,')) {
                continue;
            }

            $slot = $secrets->matches($message, self::decode(substr($entry, 3)), SecretEncoding::StandardWebhooks);

            if ($slot !== null) {
                return new VerifiedWebhook($id, $signedAt, null, true, $slot);
            }
        }

        throw WebhookSignatureException::because(RejectionReason::InvalidSignature);
    }

    public function sign(OutgoingWebhook $message, EndpointSecrets $secrets): SignatureHeaders
    {
        $timestamp = (string) $message->timestamp->getTimestamp();
        $macs = $secrets->sign(self::signedContent($message->id, $timestamp, $message->body), SecretEncoding::StandardWebhooks);

        return new SignatureHeaders([
            self::ID => $message->id,
            self::TIMESTAMP => $timestamp,
            self::SIGNATURE => implode(' ', array_map(static fn (string $mac): string => 'v1,'.Crypto::base64Encode($mac), $macs)),
        ]);
    }

    public static function signedContent(string $id, string $timestamp, string $body): string
    {
        return $id.'.'.$timestamp.'.'.$body;
    }

    /**
     * Strict standard base64; anything else becomes an empty MAC, which never matches and costs
     * the same as a wrong one.
     */
    private static function decode(string $encoded): string
    {
        try {
            return Crypto::base64Decode($encoded);
        } catch (InvalidEncodingException) {
            return '';
        }
    }
}
