<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Testing;

use Carbon\CarbonInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Sentinel\DataTransferObjects\SigningOptions;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\DataTransferObjects\SignedRequestData;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
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
 *
 * Default-scheme endpoints are signed with the endpoint's own secret (or `secret()`); RFC 9421
 * endpoints with the sentinel key named by `keyId()`.
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

    public function toRequest(): SignedRequestData
    {
        $body = $this->body ?? json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $id = $this->id ?? 'msg_'.Str::ulid();
        $timestamp = (string) (($this->at === null ? Clock::now() : Clock::utc($this->at))->getTimestamp() - $this->staleBy);
        $uri = $this->endpoint->url();

        if ($this->endpoint->signature_scheme === 'rfc9421') {
            return $this->rfc9421($uri, $id, $timestamp, $body);
        }

        $secret = $this->secret ?? ($this->previous ? $this->endpoint->previous_secret : $this->endpoint->secret) ?? '';
        $macs = app(MacEngine::class)->sign(DefaultScheme::signedContent($id, $timestamp, $body), [$secret], SecretEncoding::StandardWebhooks);
        $signature = implode(' ', array_map(static fn (string $mac): string => 'v1,'.Crypto::base64Encode($mac), $macs));

        if ($this->tamper) {
            $signature = self::flip($signature);
        }

        return new SignedRequestData($uri, [
            ...$this->headers,
            DefaultScheme::ID => $id,
            DefaultScheme::TIMESTAMP => $timestamp,
            DefaultScheme::SIGNATURE => $signature,
        ], $body);
    }

    /**
     * Post it through the application's HTTP kernel.
     *
     * @return TestResponse<\Illuminate\Http\Response>
     */
    public function send(): TestResponse
    {
        $data = $this->toRequest();
        $server = [];

        foreach ($data->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        $request = Request::create($data->uri, 'POST', [], [], [], $server, $data->body);

        return TestResponse::fromBaseResponse(app(Kernel::class)->handle($request), $request);
    }

    private function rfc9421(string $uri, string $id, string $timestamp, string $body): SignedRequestData
    {
        $headers = [...$this->headers, DefaultScheme::ID => $id, DefaultScheme::TIMESTAMP => $timestamp];
        $signed = app(SentinelManager::class)->signatures()->sign(
            new PsrRequest('POST', $uri, $headers, $body),
            $this->keyId ?? 'partner',
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
