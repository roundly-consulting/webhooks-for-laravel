<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use JsonException;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Sentinel\DataTransferObjects\IdempotentResult;
use RoundlyConsulting\Sentinel\Exceptions\IdempotencyKeyReusedException;
use RoundlyConsulting\Sentinel\Exceptions\IdempotencyRequestInProgressException;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\DataTransferObjects\AcceptedDelivery;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Enums\DuplicateResponse;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Events\WebhookRejected;
use RoundlyConsulting\Webhooks\Exceptions\PayloadSanitizerException;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;
use RoundlyConsulting\Webhooks\Http\Problem;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Support\InboundLimiter;
use RoundlyConsulting\Webhooks\Support\Keys;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\UrlKeys;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inbound pipeline, in a fixed order — the first failure wins, and none of them stores or
 * queues anything:
 *
 *  1. key shape (404) · 2. endpoint lookup — unknown, deleted and disabled all 404 ·
 *  3. rejection guard per endpoint + IP (429) · 4. size (413) · 5. content type (415) ·
 *  5b. per-endpoint limit on verified deliveries (429 — checked here, spent only by a verified
 *  delivery) · 6. signature + timestamp (401) · 7. JSON decode, arrays only (400) ·
 *  8. replay / idempotency (200 duplicate or 409) · 9. store + queue → 202.
 *
 * Size and content type run before the signature, so an unauthenticated caller cannot make the
 * app compute MACs over megabytes. The delivery limit runs before it too: a signature verified
 * and then refused would have used up its RFC 9421 nonce, and the sender's retry would be
 * refused as a replay. Processing never happens here — only on the queue.
 */
final readonly class ReceiveWebhookAction
{
    public function __construct(
        private VerifyInboundRequestAction $verify,
        private AcceptInboundDeliveryAction $accept,
        private InboundLimiter $limiter,
        private SentinelManager $sentinel,
        private Dispatcher $events,
    ) {}

    public function execute(string $key, Request $request): Response
    {
        if (! UrlKeys::valid($key)) {
            return Problem::response(RejectionReason::NotFound);
        }

        $endpoint = ModelSeams::inboundEndpoint()::query()->where('url_key', $key)->first();

        // Unknown keys fire nothing: a stranger cannot spam the host's listeners.
        if ($endpoint === null) {
            return Problem::response(RejectionReason::NotFound);
        }

        if (! $endpoint->enabled) {
            return $this->refuse($endpoint, $request, RejectionReason::EndpointDisabled, hit: false);
        }

        $ip = $request->ip() ?? 'unknown';
        $wait = $this->limiter->rejectionsExhausted(Keys::of($endpoint), $ip);

        if ($wait !== null) {
            return $this->rateLimited($endpoint, $request, 'rej:'.Keys::of($endpoint).':'.$ip, $wait);
        }

        $limit = min($endpoint->max_payload_bytes ?? WebhooksConfig::inboundMaxPayloadBytes(), WebhooksConfig::inboundPayloadCeiling());
        $declared = $request->headers->get('Content-Length');

        if (is_string($declared) && ctype_digit($declared) && (int) $declared > $limit) {
            return $this->refuse($endpoint, $request, RejectionReason::PayloadTooLarge);
        }

        $body = self::readBody($request, $limit);

        if ($body === null) {
            return $this->refuse($endpoint, $request, RejectionReason::PayloadTooLarge);
        }

        if (! self::acceptsContentType($request, $endpoint)) {
            return $this->refuse($endpoint, $request, RejectionReason::UnsupportedContentType);
        }

        $wait = $this->limiter->deliveriesExhausted(Keys::of($endpoint), $endpoint->rate_limit_per_minute ?? WebhooksConfig::inboundRateLimit());

        if ($wait !== null) {
            return $this->rateLimited($endpoint, $request, 'del:'.Keys::of($endpoint), $wait);
        }

        try {
            $verified = $this->verify->execute($endpoint, $request, $body);
        } catch (WebhookSignatureException $exception) {
            if ($exception->reason() === RejectionReason::Replayed && $this->replayOfStored($endpoint, $request)) {
                return $this->duplicate();
            }

            return $this->refuse($endpoint, $request, $exception->reason());
        }

        $this->limiter->hitDelivery(Keys::of($endpoint));

        $payload = self::decode($body);

        if ($payload === null) {
            return $this->refuse($endpoint, $request, RejectionReason::InvalidPayload);
        }

        $idempotencyKey = self::idempotencyKey($verified, $request);

        // No delivery id at all (a scheme without one, and no Idempotency-Key): a malformed request.
        if ($idempotencyKey === null) {
            return $this->refuse($endpoint, $request, RejectionReason::MissingId, hit: false, status: 400);
        }

        try {
            $result = $this->store($endpoint, $verified, $idempotencyKey, $payload, self::headers($request), $body);
        } catch (IdempotencyKeyReusedException) {
            return $this->refuse($endpoint, $request, RejectionReason::IdempotencyKeyReused, hit: false);
        } catch (IdempotencyRequestInProgressException) {
            return Problem::response(RejectionReason::InProgress, ['Retry-After' => '1']);
        } catch (PayloadSanitizerException) {
            return Problem::response(RejectionReason::SanitizerFailed);
        }

        if ($result->duplicate) {
            // A duplicate of a delivery stored while the queue was down queues it now.
            $this->accept->requeueStalled($endpoint, $idempotencyKey);

            return $this->duplicate();
        }

        return Problem::accepted($result->id);
    }

    /**
     * The idempotency run: once per delivery id per endpoint, fingerprinted by the body digest.
     * An id the signature does not cover gets a second guard on the body itself, so a captured
     * delivery resent under a fresh id header is still a duplicate.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function store(InboundEndpoint $endpoint, VerifiedWebhook $verified, string $idempotencyKey, array $payload, array $headers, string $body): AcceptedDelivery
    {
        $digest = Crypto::digest()->hex($body);
        $scope = 'webhooks:in:'.Keys::of($endpoint);
        $ttl = WebhooksConfig::dedupeTtl();
        // The store keeps plain arrays: converted at this boundary, both ways.
        $accept = fn (): array => $this->accept->execute($endpoint, $verified, $idempotencyKey, $payload, $headers, $body)->toStored();
        $run = fn (): array => self::outcome($this->sentinel->idempotency()->run($idempotencyKey, $scope, $accept, $digest, $ttl))->toStored();

        if ($verified->idIsSigned) {
            return AcceptedDelivery::fromStored($run());
        }

        return self::outcome($this->sentinel->idempotency()->run('body:'.$digest, $scope.':body', $run, $digest, $ttl));
    }

    private static function outcome(IdempotentResult $result): AcceptedDelivery
    {
        return AcceptedDelivery::fromStored($result->value, $result->replayed);
    }

    private function duplicate(): Response
    {
        return WebhooksConfig::onDuplicate() === DuplicateResponse::Accept
            ? Problem::duplicate()
            : Problem::response(RejectionReason::Conflict);
    }

    private function refuse(InboundEndpoint $endpoint, Request $request, RejectionReason $reason, bool $hit = true, ?int $status = null): Response
    {
        if ($hit) {
            $this->limiter->hitRejection(Keys::of($endpoint), $request->ip() ?? 'unknown');
        }

        $this->announce($endpoint, $request, $reason, $status ?? $reason->status());

        return Problem::response($reason, status: $status);
    }

    private function rateLimited(InboundEndpoint $endpoint, Request $request, string $flag, int $wait): Response
    {
        if ($this->limiter->firstInWindow($flag)) {
            $this->announce($endpoint, $request, RejectionReason::RateLimited, 429);
        }

        return Problem::response(RejectionReason::RateLimited, ['Retry-After' => (string) $wait]);
    }

    private function announce(InboundEndpoint $endpoint, Request $request, RejectionReason $reason, int $status): void
    {
        $agent = $request->userAgent();

        $this->events->dispatch(new WebhookRejected(
            Keys::of($endpoint),
            $reason,
            $status,
            $request->ip(),
            $agent === null ? null : mb_substr($agent, 0, 255),
        ));
    }

    /**
     * An RFC 9421 nonce seen before is a duplicate only when its delivery is stored — sentinel
     * remembers a nonce as soon as the signature verifies, before any later step. The delivery
     * may be stored under its id header, its `Idempotency-Key` or (neither sent) its nonce.
     */
    private function replayOfStored(InboundEndpoint $endpoint, Request $request): bool
    {
        $options = $endpoint->scheme_options ?? [];
        $header = $options['id_header'] ?? Rfc9421Scheme::DEFAULT_ID_HEADER;
        $keys = array_filter([
            self::normalised(is_string($header) ? $request->headers->get($header) : null),
            self::normalised(self::headerKey($request)),
            ...array_map(static fn (string $nonce): ?string => self::normalised('nonce:'.$nonce), self::nonces($request)),
        ]);

        return $keys !== [] && ModelSeams::inboundDelivery()::query()
            ->where('endpoint_id', Keys::of($endpoint))
            ->whereIn('idempotency_key', array_values($keys))
            ->exists();
    }

    /**
     * The `nonce` parameters of a request's `Signature-Input` (RFC 9651 strings, unescaped).
     *
     * @return list<string>
     */
    private static function nonces(Request $request): array
    {
        $input = $request->headers->get('Signature-Input');

        if (! is_string($input) || preg_match_all('/;\s*nonce="((?:[^"\\\\]|\\\\.)*)"/', $input, $matches) < 1) {
            return [];
        }

        return array_map(static fn (string $nonce): string => (string) preg_replace('/\\\\(.)/', '$1', $nonce), $matches[1]);
    }

    /**
     * The body, read at most `$limit + 1` bytes (a chunked body has no Content-Length); null
     * when it is over the limit.
     */
    private static function readBody(Request $request, int $limit): ?string
    {
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $limit + 1);

        if (! is_string($body) || strlen($body) > $limit) {
            return null;
        }

        return $body;
    }

    private static function acceptsContentType(Request $request, InboundEndpoint $endpoint): bool
    {
        $header = $request->headers->get('Content-Type');

        if (! is_string($header)) {
            return false;
        }

        $type = strtolower(trim(explode(';', $header, 2)[0]));

        foreach ($endpoint->allowed_content_types ?? WebhooksConfig::inboundContentTypes() as $allowed) {
            $pattern = '#^'.str_replace('\*', '[a-z0-9!\#$&^_.+-]+', preg_quote($allowed, '#')).'$#D';

            if ($allowed === $type || preg_match($pattern, $type) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * JSON to arrays only (never objects), depth ≤ 64, big integers kept as strings. A scalar top
     * level is refused.
     *
     * @return array<array-key, mixed>|null
     */
    private static function decode(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The scheme's delivery id wins (a signed id cannot be split by a forged header); else the
     * `Idempotency-Key` header when accepted.
     */
    private static function idempotencyKey(VerifiedWebhook $verified, Request $request): ?string
    {
        if ($verified->deliveryId !== null && $verified->deliveryId !== '') {
            return self::normalised($verified->deliveryId);
        }

        return WebhooksConfig::acceptIdempotencyHeader() ? self::normalised(self::headerKey($request)) : null;
    }

    /**
     * An `Idempotency-Key` header: an RFC 9651 string (`"…"`) or a bare token.
     */
    private static function headerKey(Request $request): ?string
    {
        $value = $request->headers->get('Idempotency-Key');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return trim($value);
    }

    /**
     * 1–255 printable ASCII bytes as is; longer (or other) keys become `sha256:<hex>`.
     */
    private static function normalised(?string $key): ?string
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return strlen($key) <= 255 && preg_match('/^[\x20-\x7e]+$/D', $key) === 1
            ? $key
            : 'sha256:'.Crypto::digest()->hex($key);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $first = $values[0] ?? null;

            if (is_string($first)) {
                $headers[strtolower($name)] = $first;
            }
        }

        return $headers;
    }
}
