<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes;

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
 * The docs recipe: `X-Hub-Signature-256: sha256=<hex HMAC(body)>`, `X-GitHub-Delivery`,
 * `X-GitHub-Event`. No timestamp — replay protection is the dedupe alone (unsigned id).
 */
final readonly class GitHubScheme implements SignatureScheme
{
    public function verify(IncomingWebhook $request, EndpointSecrets $secrets): VerifiedWebhook
    {
        $header = $request->header('x-hub-signature-256') ?? throw WebhookSignatureException::because(RejectionReason::MissingSignature);

        try {
            $mac = str_starts_with($header, 'sha256=') ? Crypto::hexDecode(substr($header, 7)) : '';
        } catch (InvalidEncodingException) {
            $mac = '';
        }

        $slot = $secrets->matches($request->body, $mac, SecretEncoding::Raw) ?? throw WebhookSignatureException::because(RejectionReason::InvalidSignature);

        return new VerifiedWebhook($request->header('x-github-delivery'), null, $request->header('x-github-event'), false, $slot);
    }
}
