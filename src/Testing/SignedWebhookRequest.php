<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Testing;

use Carbon\CarbonInterface;
use Closure;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use RoundlyConsulting\Sentinel\DataTransferObjects\SigningOptions;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Contracts\SignsOutboundWebhooks;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutgoingWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\SignatureHeaders;
use RoundlyConsulting\Webhooks\DataTransferObjects\SignedRequestData;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use SensitiveParameter;

/**
 * Builds a correctly signed inbound request for a host's tests — and the broken variants the
 * pipeline must refuse:
 *
 *     SignedWebhookRequest::for($endpoint)->payload(['type' => 'invoice.paid'])->send()->assertAccepted();
 *     SignedWebhookRequest::for($endpoint)->tamper()->send()->assertUnauthorized();
 *     SignedWebhookRequest::for($endpoint)->unsigned()->send()->assertUnauthorized();
 *
 * Signed with the endpoint's own secret (or `secret()`, or the previous one) by the endpoint's
 * scheme: the default scheme, any scheme that implements `SignsOutboundWebhooks` (a GitHub- or
 * Stripe-style recipe with a `sign()`), or the closure given to `signUsing()`. RFC 9421 endpoints
 * are signed with the sentinel key named by `keyId()` (else the endpoint's `scheme_options.key_id`).
 */
final class SignedWebhookRequest
{
    private ?string $body = null;

    /** @var array<array-key, mixed> */
    private array $payload = ['type' => 'test.event', 'data' => []];

    private ?string $id = null;

    private ?CarbonInterface $at = null;

    private ?string $secret = null;

    /** @var array<string, string> */
    private array $headers = ['Content-Type' => 'application/json'];

    private bool $tamper = false;

    private int $staleBy = 0;

    private bool $previous = false;

    private ?string $keyId = null;

    private bool $unsigned = false;

    /** @var list<string> lower-cased */
    private array $without = [];

    /** @var (Closure(OutgoingWebhook, EndpointSecrets): SignatureHeaders)|null */
    private ?Closure $signer = null;

    private function __construct(private readonly InboundEndpoint $endpoint) {}

    public static function for(InboundEndpoint $endpoint): self
    {
        return new self($endpoint);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function payload(array $payload): self
    {
        $this->payload = $payload;
        $this->body = null;

        return $this;
    }

    /**
     * The exact body bytes (no JSON encoding).
     */
    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function at(CarbonInterface $at): self
    {
        $this->at = $at;

        return $this;
    }

    public function secret(#[SensitiveParameter] string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Flip one byte of the signature.
     */
    public function tamper(): self
    {
        $this->tamper = true;

        return $this;
    }

    /**
     * Sign with a timestamp this many seconds in the past.
     */
    public function staleBy(int $seconds): self
    {
        $this->staleBy = $seconds;

        return $this;
    }

    /**
     * Sign with the endpoint's previous secret (rotation grace).
     */
    public function signedWithPrevious(): self
    {
        $this->previous = true;

        return $this;
    }

    /**
     * The sentinel key an RFC 9421 endpoint's request is signed with.
     */
    public function keyId(string $keyId): self
    {
        $this->keyId = $keyId;

        return $this;
    }

    /**
     * Sign a custom scheme's request with this closure — for a scheme without `sign()`.
     *
     * @param  Closure(OutgoingWebhook, EndpointSecrets): SignatureHeaders  $signer
     */
    public function signUsing(Closure $signer): self
    {
        $this->signer = $signer;

        return $this;
    }

    /**
     * No signature at all (the default scheme still sends its id and timestamp).
     */
    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    /**
     * Drop a header after signing (`withoutHeader('webhook-timestamp')`).
     */
    public function withoutHeader(string $name): self
    {
        $this->without[] = strtolower($name);

        return $this;
    }

    /**
     * Send an `Idempotency-Key` header.
     */
    public function idempotencyKey(string $key): self
    {
        return $this->withHeader('Idempotency-Key', $key);
    }

    /**
     * @throws LogicException when the request cannot be signed as asked
     */
    public function toRequest(): SignedRequestData
    {
        $body = $this->body ?? json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $id = $this->id ?? 'msg_'.Str::ulid();
        $timestamp = ($this->at === null ? Clock::now() : Clock::utc($this->at))->subSeconds($this->staleBy);
        $uri = $this->endpoint->url();
        $scheme = $this->endpoint->signature_scheme;

        $data = match (true) {
            $this->unsigned => $this->unsignedRequest($uri, $id, $timestamp, $body),
            $scheme === 'rfc9421' => $this->rfc9421($uri, $id, (string) $timestamp->getTimestamp(), $body),
            default => $this->signed($uri, new OutgoingWebhook($id, $timestamp, $body, $this->eventType())),
        };

        return new SignedRequestData(
            $data->uri,
            array_filter($data->headers, fn (string $name): bool => ! in_array(strtolower($name), $this->without, true), ARRAY_FILTER_USE_KEY),
            $data->body,
        );
    }

    /**
     * Post it through the application's HTTP kernel.
     *
     * @return TestResponse<Response>
     */
    public function send(): TestResponse
    {
        $data = $this->toRequest();
        $request = Request::create($data->uri, 'POST', [], [], [], $data->server(), $data->body);

        return TestResponse::fromBaseResponse(app(Kernel::class)->handle($request), $request);
    }

    /**
     * The default scheme, a scheme with `sign()`, or the `signUsing()` closure — with the chosen
     * secret only.
     */
    private function signed(string $uri, OutgoingWebhook $message): SignedRequestData
    {
        $secrets = new EndpointSecrets([$this->chosenSecret()], app(MacEngine::class));
        $scheme = $this->signer === null ? app(SchemeRegistry::class)->resolve($this->endpoint->signature_scheme) : null;

        $headers = match (true) {
            $this->signer !== null => ($this->signer)($message, $secrets),
            $scheme instanceof SignsOutboundWebhooks => $scheme->sign($message, $secrets),
            default => throw new LogicException("The [{$this->endpoint->signature_scheme}] scheme cannot sign: give it a sign() (SignsOutboundWebhooks) or call signUsing()."),
        };
        $headers = $headers->headers;
        $body = $message->body;

        if ($this->tamper && $scheme instanceof DefaultScheme) {
            $headers[DefaultScheme::SIGNATURE] = self::flip($headers[DefaultScheme::SIGNATURE]);
        } elseif ($this->tamper) {
            // Another scheme's signature format is its own: change the signed bytes instead.
            $body .= ' ';
        }

        return new SignedRequestData($uri, [...$this->headers, ...$headers], $body);
    }

    private function unsignedRequest(string $uri, string $id, CarbonInterface $timestamp, string $body): SignedRequestData
    {
        $headers = $this->endpoint->signature_scheme === 'default'
            ? [DefaultScheme::ID => $id, DefaultScheme::TIMESTAMP => (string) $timestamp->getTimestamp()]
            : [];

        return new SignedRequestData($uri, [...$this->headers, ...$headers], $body);
    }

    /**
     * @throws LogicException
     */
    private function chosenSecret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        $secret = $this->previous ? $this->endpoint->previous_secret : $this->endpoint->secret;

        if ($secret === null || $secret === '') {
            throw new LogicException($this->previous
                ? 'The endpoint has no previous secret to sign with: rotate its secret first.'
                : 'The endpoint has no secret to sign with: pass one with secret().');
        }

        return $secret;
    }

    private function eventType(): string
    {
        $type = $this->body === null ? ($this->payload['type'] ?? null) : null;

        return is_string($type) && $type !== '' ? $type : 'test.event';
    }

    /**
     * @throws LogicException
     */
    private function rfc9421(string $uri, string $id, string $timestamp, string $body): SignedRequestData
    {
        $keyId = $this->keyId ?? ($this->endpoint->scheme_options['key_id'] ?? null);

        if (! is_string($keyId) || $keyId === '') {
            throw new LogicException('An RFC 9421 request needs the sentinel key to sign with: call keyId(), or set the endpoint\'s scheme_options.key_id.');
        }

        $headers = [...$this->headers, DefaultScheme::ID => $id, DefaultScheme::TIMESTAMP => $timestamp];
        $signed = app(SentinelManager::class)->signatures()->sign(
            new PsrRequest('POST', $uri, $headers, $body),
            $keyId,
            new SigningOptions(components: Rfc9421Scheme::OUTBOUND_COMPONENTS),
        );

        foreach ($signed->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        if ($this->tamper && isset($headers['Signature'])) {
            $headers['Signature'] = self::flip($headers['Signature']);
        }

        return new SignedRequestData($uri, $headers, $body);
    }

    private static function flip(string $signature): string
    {
        $position = (int) strpos($signature, ',') + 2;

        return substr_replace($signature, $signature[$position] === 'A' ? 'B' : 'A', $position, 1);
    }
}
