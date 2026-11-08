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
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
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
        $endpoint = $delivery?->endpoint;

        if ($delivery === null || ! $endpoint instanceof InboundEndpoint) {
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
     * The handler failed on its last try: a safe error (the exception's short name and a
     * truncated message — never the payload) and `WebhookProcessingFailed`.
     */
    public function fail(int|string $deliveryId, ?Throwable $exception): void
    {
        $delivery = ModelSeams::inboundDelivery()::query()->find($deliveryId);

        if (! $delivery instanceof InboundDelivery) {
            return;
        }

        $delivery->forceFill([
            'status' => InboundStatus::Failed,
            'failed_at' => Clock::now(),
            'last_error_code' => 'handler_failed',
            'last_error_message' => $exception === null ? null : mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 500),
        ])->save();

        $this->events->dispatch(new WebhookProcessingFailed($delivery, 'handler_failed'));
    }
}
