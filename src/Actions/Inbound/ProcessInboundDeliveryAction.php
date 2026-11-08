<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Events\WebhookProcessingFailed;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use Throwable;

/**
 * One run of an inbound delivery, on the queue: fire `WebhookReceived`, then the endpoint's
 * handler (none bound: the event only). A throwing handler leaves the job to retry;
 * {@see self::fail()} records the last failure.
 *
 * @internal
 */
final readonly class ProcessInboundDeliveryAction
{
    public function __construct(
        private HandlerRegistry $handlers,
        private Dispatcher $events,
    ) {}

    /**
     * @throws UnknownHandlerException
     */
    public function execute(int|string $deliveryId, int $run, bool $isReplay, int $attempt): void
    {
        $delivery = ModelSeams::inboundDelivery()::query()->find($deliveryId);

        if ($delivery === null) {
            return;
        }

        $endpoint = ModelSeams::inboundEndpoint()::query()->withTrashed()->find($delivery->endpoint_id);

        // Deleted before its delivery ran: fail it, never leave it `queued` forever.
        if ($endpoint === null || $endpoint->trashed()) {
            $this->fail($deliveryId, null, 'endpoint_deleted');

            return;
        }

        $started = Clock::now();
        $delivery->forceFill([
            'status' => InboundStatus::Processing,
            'processing_started_at' => $started,
            'attempts' => $delivery->attempts + 1,
        ])->save();

        $webhook = new InboundWebhook(
            $delivery->public_id,
            $endpoint,
            $delivery->event_type,
            $delivery->payload ?? [],
            $delivery->headers ?? [],
            $delivery->received_at,
            $isReplay,
            $run,
            $attempt,
        );

        $this->events->dispatch(new WebhookReceived($webhook));

        if ($endpoint->handler !== null) {
            $this->handlers->resolve($endpoint->handler)->handle($webhook);
        }

        $delivery->forceFill([
            'status' => InboundStatus::Processed,
            'processed_at' => Clock::now(),
            'failed_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'duration_ms' => (int) round($started->diffInMilliseconds(Clock::now(), true)),
        ])->save();
    }

    /**
     * The handler failed on its last try (`handler_failed`), or the endpoint is gone
     * (`endpoint_deleted`): a safe error and `WebhookProcessingFailed`. Only the exception's short
     * name (and a short code, such as an SQLSTATE) is stored — a message may quote the payload or
     * the SQL with its bound values; the full exception goes to `report()` with the failed job.
     */
    public function fail(int|string $deliveryId, ?Throwable $exception, string $code = 'handler_failed', ?int $run = null): void
    {
        // Only the current run, and never a processed one: a released duplicate copy that runs
        // out of tries after the run succeeded (or after a replay opened the next run) must not
        // overwrite its outcome.
        $failed = ModelSeams::inboundDelivery()::query()
            ->whereKey($deliveryId)
            ->where('status', '!=', InboundStatus::Processed->value)
            ->when($run !== null, static fn ($query) => $query->where('runs', $run))
            ->update([
                'status' => InboundStatus::Failed->value,
                'failed_at' => Clock::format(Clock::now()),
                'last_error_code' => $code,
                'last_error_message' => $exception === null ? null : self::summary($exception),
                'updated_at' => Clock::format(Clock::now()),
            ]);
        $delivery = $failed === 1 ? ModelSeams::inboundDelivery()::query()->find($deliveryId) : null;

        if (! $delivery instanceof InboundDelivery) {
            return;
        }

        $this->events->dispatch(new WebhookProcessingFailed($delivery, $code));
    }

    private static function summary(Throwable $exception): string
    {
        $code = (string) $exception->getCode();

        return class_basename($exception).($code !== '0' && preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $code) === 1 ? " ({$code})" : '');
    }
}
