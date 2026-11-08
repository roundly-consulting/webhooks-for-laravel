<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Jobs\SyncJob;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\JobReleasedException;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\RateLimitManager;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Contracts\SignsOutboundWebhooks;
use RoundlyConsulting\Webhooks\DataTransferObjects\AttemptOutcome;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutgoingWebhook;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Events\DeliveryFailed;
use RoundlyConsulting\Webhooks\Events\DeliverySucceeded;
use RoundlyConsulting\Webhooks\Exceptions\SigningUnavailableException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\EndpointSecrets;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Support\Backoff;
use RoundlyConsulting\Webhooks\Support\BodyReader;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\DestinationPolicyFactory;
use RoundlyConsulting\Webhooks\Support\HeaderRules;
use RoundlyConsulting\Webhooks\Support\Keys;
use RoundlyConsulting\Webhooks\Support\MacEngine;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\ResponseSink;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use Throwable;

/**
 * One attempt of an outbound delivery, run by `DeliverWebhook`:
 *
 *  1. claim the row with the job's dispatch token (a duplicate or superseded job finds nothing);
 *  2. a disabled or deleted endpoint cancels the delivery — no request;
 *  3. throttle per endpoint (http-client-rate-limits), before any DNS lookup; a throttle parks
 *     the row and consumes no attempt;
 *  4. vet + pin the destination on every hop (safe-fetch), sign with a fresh timestamp, POST the
 *     stored body, read a capped answer (guard off: through a capped sink) and flag a cut;
 *  5. record the attempt and classify: 2xx succeeds, 410 disables, a blocked destination fails
 *     for good, anything else retries with backoff (Retry-After honoured) until `max_attempts` —
 *     an unexpected exception too (`unexpected`, reported), which never counts against the endpoint.
 *
 * @internal
 */
final readonly class AttemptDeliveryAction
{
    /** Seconds a claim outlives the request's own time budget. */
    private const int CLAIM_SLACK = 30;

    public function __construct(
        private Container $container,
        private HttpFactory $http,
        private SafeFetchManager $safeFetch,
        private DestinationPolicyFactory $policies,
        private SchemeRegistry $schemes,
        private Backoff $backoff,
        private ScheduleRetryAction $retry,
        private RecordEndpointFailureAction $failures,
        private DisableOutboundEndpointAction $disable,
        private Dispatcher $events,
    ) {}

    /**
     * @param  object  $job  the queued job (released back onto the queue when throttled)
     */
    public function execute(int|string $deliveryId, string $token, object $job): void
    {
        $delivery = $this->claim($deliveryId, $token);

        if ($delivery === null) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof OutboundEndpoint || ! $endpoint->enabled || $endpoint->trashed()) {
            $delivery->forceFill(['status' => OutboundStatus::Cancelled, 'claimed_until' => null, 'dispatch_token' => null, 'next_attempt_at' => null])->save();

            return;
        }

        $started = Clock::now();
        $clock = hrtime(true);

        try {
            $outcome = $this->outcome($this->send($delivery, $endpoint, $job), $clock);
        } catch (JobReleasedException $exception) {
            $parkable = self::parkable($job);
            $this->park($delivery, $exception->delaySeconds, keepToken: $parkable);

            if ($parkable) {
                throw $exception;
            }

            return;
        } catch (RateLimitExceededException $exception) {
            $this->park($delivery, (int) ceil($exception->delayMs / 1000), keepToken: false);

            return;
        } catch (DestinationRejected $exception) {
            $rule = $exception->blockedBy();
            $outcome = self::failure(AttemptError::fromReason($exception->reason()), $exception->reason()->value.($rule === null ? '' : " ({$rule})"), true, $clock);
        } catch (DestinationUnavailable $exception) {
            $outcome = self::failure(AttemptError::fromReason($exception->reason()), $exception->reason()->value, false, $clock);
        } catch (ConnectionException $exception) {
            $outcome = self::connectionFailure($exception, $clock);
        } catch (SigningUnavailableException|UnknownSchemeException) {
            $outcome = self::failure(AttemptError::Signing, 'signing_unavailable', true, $clock);
        } catch (Throwable $exception) {
            // Anything else (a host-wide HTTP option the guard refuses, a secret that no longer
            // decrypts, a throwing global middleware) is still an attempt: recorded and retried
            // with backoff like a failure, never left `sending` for the sweep to call lost.
            report($exception);
            $outcome = self::failure(AttemptError::Unexpected, class_basename($exception), false, $clock);
        }

        // An attempt that outlived its claim may have been recorded `worker_lost` and dispatched
        // again by the sweep meanwhile: then it is no longer this job's to record.
        if (! $this->stillOwned($delivery, $token)) {
            return;
        }

        $this->record($delivery, $endpoint, $started, $outcome);
    }

    /**
     * Re-take the claim for the moment of recording — only while this job's token still holds
     * the row in `sending`.
     */
    private function stillOwned(OutboundDelivery $delivery, string $token): bool
    {
        return ModelSeams::outboundDelivery()::query()
            ->whereKey($delivery->getKey())
            ->where('dispatch_token', $token)
            ->where('status', OutboundStatus::Sending->value)
            ->update(['claimed_until' => Clock::format(Clock::now()->addSeconds(self::CLAIM_SLACK))]) === 1;
    }

    /**
     * The worker died, or the job threw past `maxExceptions`: hand the row to the sweep.
     */
    public function abandon(int|string $deliveryId, string $token): void
    {
        ModelSeams::outboundDelivery()::query()
            ->whereKey($deliveryId)
            ->where('dispatch_token', $token)
            ->whereIn('status', [OutboundStatus::Pending->value, OutboundStatus::Retrying->value, OutboundStatus::Sending->value])
            ->update([
                'status' => OutboundStatus::Retrying->value,
                'next_attempt_at' => Clock::format(Clock::now()->addMinute()),
                'claimed_until' => null,
                'dispatch_token' => null,
            ]);
    }

    private function claim(int|string $deliveryId, string $token): ?OutboundDelivery
    {
        $now = Clock::now();
        $until = $now->addSeconds(WebhooksConfig::connectTimeout() + WebhooksConfig::totalTimeout() + self::CLAIM_SLACK);

        $claimed = ModelSeams::outboundDelivery()::query()
            ->whereKey($deliveryId)
            ->where('dispatch_token', $token)
            ->whereIn('status', [OutboundStatus::Pending->value, OutboundStatus::Retrying->value])
            ->where(static fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<', Clock::format($now)))
            ->update([
                'status' => OutboundStatus::Sending->value,
                'claimed_until' => Clock::format($until),
                'updated_at' => Clock::format($now),
            ]);

        return $claimed === 1 ? ModelSeams::outboundDelivery()::query()->find($deliveryId) : null;
    }

    private function send(OutboundDelivery $delivery, OutboundEndpoint $endpoint, object $job): Response
    {
        $limit = $this->container->make(RateLimitManager::class)
            ->releasingJob($job)
            ->perMinute($endpoint->rate_limit_per_minute ?? WebhooksConfig::outboundRateLimit())
            ->by('webhooks:out:'.Keys::of($endpoint))
            ->adaptive()
            ->maxWait(WebhooksConfig::maxDelay() * 1000);

        $body = $delivery->body ?? '';
        $request = $this->http->rateLimit($limit)
            ->connectTimeout(WebhooksConfig::connectTimeout())
            ->timeout(WebhooksConfig::totalTimeout())
            // One spelling (the stored names are lower case): an endpoint's own `user-agent`
            // replaces the package's instead of joining it as a second value.
            ->withHeaders(['user-agent' => WebhooksConfig::userAgent(), ...HeaderRules::sendable($endpoint->headers ?? [])]);

        $request = $this->sign($request, $delivery, $endpoint, $body);
        // Guarded: safe-fetch reads at most max_response_bytes. Unguarded: a sink keeps one byte
        // more (that byte says "truncated") and discards the rest — never the whole answer in memory.
        $request = WebhooksConfig::ssrfEnabled()
            ? $this->safeFetch->guard($request, $this->policies->make())
            : $request->withoutRedirecting()->withOptions(['sink' => ResponseSink::open(WebhooksConfig::maxResponseBytes() + 1)]);

        return $request->withBody($body, 'application/json')->post($endpoint->url);
    }

    /**
     * @throws SigningUnavailableException|UnknownSchemeException
     */
    private function sign(PendingRequest $request, OutboundDelivery $delivery, OutboundEndpoint $endpoint, string $body): PendingRequest
    {
        $scheme = $this->schemes->resolve($endpoint->signature_scheme);
        $message = new OutgoingWebhook($delivery->webhookId(), Clock::now(), $body, $delivery->event_type);

        if ($scheme instanceof SignsOutboundWebhooks) {
            $secrets = new EndpointSecrets($endpoint->activeSecrets(), $this->container->make(MacEngine::class));

            return $request->withHeaders($scheme->sign($message, $secrets)->headers);
        }

        $keyId = $endpoint->scheme_options['key_id'] ?? WebhooksConfig::rfc9421OutboundKeyId();

        if (! $scheme instanceof Rfc9421Scheme || ! is_string($keyId) || $keyId === '') {
            throw SigningUnavailableException::make();
        }

        return $scheme->prepare($request->withHeaders([
            DefaultScheme::ID => $message->id,
            DefaultScheme::TIMESTAMP => (string) $message->timestamp->getTimestamp(),
        ]), $keyId);
    }

    private function outcome(Response $response, int|float $clock): AttemptOutcome
    {
        $status = $response->status();
        $remote = $response->handlerStats()['primary_ip'] ?? null;
        $error = match (true) {
            $status >= 200 && $status < 300 => null,
            $status >= 300 && $status < 400 => AttemptError::RedirectNotFollowed,
            default => AttemptError::HttpStatus,
        };

        $excerpt = BodyReader::read($response->toPsrResponse()->getBody(), WebhooksConfig::maxResponseBytes());

        return new AttemptOutcome(
            $status,
            $excerpt->text,
            $excerpt->truncated || $this->safeFetch->truncated($response),
            $this->container->make(RateLimitManager::class)->retryAfter($response),
            is_string($remote) && $remote !== '' ? $remote : null,
            $error,
            $error === null ? null : "HTTP {$status}",
            false,
            self::elapsed($clock),
        );
    }

    private function record(OutboundDelivery $delivery, OutboundEndpoint $endpoint, CarbonImmutable $started, AttemptOutcome $outcome): void
    {
        $number = $delivery->attempts + 1;
        $class = ModelSeams::outboundAttempt();

        $attempt = new $class;
        $attempt->forceFill([
            'delivery_id' => $delivery->getKey(),
            'series' => $delivery->series,
            'number' => $number,
            'manual' => $delivery->series > 1,
            'started_at' => $started,
            'duration_ms' => $outcome->durationMs,
            'response_status' => $outcome->status,
            'response_body' => $outcome->responseBody,
            'response_truncated' => $outcome->truncated,
            'retry_after_seconds' => $outcome->retryAfter,
            'remote_address' => $outcome->remoteAddress,
            'error_code' => $outcome->error,
            'error_message' => $outcome->errorMessage,
        ])->save();

        $delivery->forceFill([
            'attempts' => $number,
            'last_attempt_at' => $started,
            'last_response_status' => $outcome->status,
            'last_duration_ms' => $outcome->durationMs,
            'last_error_code' => $outcome->error,
            'last_error_message' => $outcome->errorMessage,
            'claimed_until' => null,
        ]);

        if ($outcome->succeeded()) {
            $this->succeed($delivery, $endpoint, $attempt);

            return;
        }

        if ($outcome->status === 410 && WebhooksConfig::disableOnGone()) {
            $this->fail($delivery, $attempt);
            $this->disable->execute($endpoint, DisableReason::Gone);

            return;
        }

        if ($outcome->terminal || $number >= $delivery->max_attempts) {
            $this->fail($delivery, $attempt);

            // A fault on this side of the wire (no way to sign, an unexpected exception) says
            // nothing about the receiver's endpoint.
            if ($outcome->error !== AttemptError::Unexpected && $outcome->error !== AttemptError::Signing) {
                $this->failures->execute($endpoint);
            }

            return;
        }

        $delay = $this->backoff->delay($number, $outcome->retryAfter);
        $next = Clock::now()->addSeconds($delay);

        $attempt->forceFill(['next_attempt_at' => $next])->save();
        $delivery->forceFill(['status' => OutboundStatus::Retrying, 'next_attempt_at' => $next, 'dispatch_token' => null])->save();
        $this->events->dispatch(new DeliveryFailed($delivery, $attempt, false, $next));
        $this->retry->execute($delivery, $delay);
    }

    private function succeed(OutboundDelivery $delivery, OutboundEndpoint $endpoint, OutboundAttempt $attempt): void
    {
        $now = Clock::now();

        $delivery->forceFill([
            'status' => OutboundStatus::Succeeded,
            'succeeded_at' => $now,
            'next_attempt_at' => null,
            'dispatch_token' => null,
        ])->save();

        ModelSeams::outboundEndpoint()::query()->whereKey($endpoint->getKey())->update([
            'consecutive_failures' => 0,
            'last_success_at' => Clock::format($now),
        ]);

        $this->events->dispatch(new DeliverySucceeded($delivery, $attempt));
    }

    private function fail(OutboundDelivery $delivery, OutboundAttempt $attempt): void
    {
        $delivery->forceFill([
            'status' => OutboundStatus::Failed,
            'failed_at' => Clock::now(),
            'next_attempt_at' => null,
            'dispatch_token' => null,
        ])->save();

        $this->events->dispatch(new DeliveryFailed($delivery, $attempt, true, null));
    }

    /**
     * A throttled attempt: back to waiting, no attempt consumed. Kept token: the released job
     * claims again. Cleared token: the sweep dispatches it when due.
     */
    private function park(OutboundDelivery $delivery, int $delaySeconds, bool $keepToken): void
    {
        $next = Clock::now()->addSeconds(max(1, $delaySeconds));

        $delivery->forceFill([
            'status' => $delivery->attempts === 0 ? OutboundStatus::Pending : OutboundStatus::Retrying,
            'claimed_until' => null,
            'next_attempt_at' => $next,
            'dispatch_token' => $keepToken ? $delivery->dispatch_token : null,
            // The released job runs at `$next`: only unclaimed well after that is it lost.
            'redispatch_at' => $keepToken ? $next->addSeconds(WebhooksConfig::outboundRequeueAfter()) : null,
        ])->save();
    }

    /**
     * Whether a released job really goes back onto a queue — not under `sync` or `dispatchSync()`,
     * where the release is a no-op and the delivery would silently vanish.
     */
    private static function parkable(object $job): bool
    {
        $queued = property_exists($job, 'job') ? $job->job : null;

        return $queued !== null && ! $queued instanceof SyncJob;
    }

    private static function failure(AttemptError $error, string $message, bool $terminal, int|float $clock): AttemptOutcome
    {
        return new AttemptOutcome(null, null, false, null, null, $error, $message, $terminal, self::elapsed($clock));
    }

    /**
     * A transport failure, classified by curl's error number — never the vendor's message.
     */
    private static function connectionFailure(ConnectionException $exception, int|float $clock): AttemptOutcome
    {
        $errno = preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) === 1 ? (int) $match[1] : null;
        $error = match ($errno) {
            28 => AttemptError::Timeout,
            6 => AttemptError::Unresolved,
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => AttemptError::Tls,
            default => AttemptError::Connection,
        };

        return self::failure($error, $errno === null ? $error->value : "{$error->value} (curl {$errno})", false, $clock);
    }

    private static function elapsed(int|float $clock): int
    {
        return (int) round((hrtime(true) - $clock) / 1_000_000);
    }
}
