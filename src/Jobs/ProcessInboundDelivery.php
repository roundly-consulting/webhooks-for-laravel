<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Sentinel\Jobs\Middleware\Idempotent;
use RoundlyConsulting\Webhooks\Actions\Inbound\ProcessInboundDeliveryAction;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use Throwable;

/**
 * Runs an inbound delivery's handler — at most once per delivery and run: a double dispatch, a
 * sender retry or the sweep re-queuing it all meet sentinel's idempotent middleware. A replay
 * opens the next run.
 */
final class ProcessInboundDelivery implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly int|string $deliveryId,
        public readonly int $run = 1,
        public readonly bool $replay = false,
    ) {
        $this->tries = WebhooksConfig::handlerTries();
        $this->timeout = WebhooksConfig::handlerTimeout();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return WebhooksConfig::handlerBackoff();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new Idempotent("webhooks:in:{$this->deliveryId}:run:{$this->run}", scope: 'webhooks', lease: $this->timeout + 30)];
    }

    public function handle(ProcessInboundDeliveryAction $action): void
    {
        $action->execute($this->deliveryId, $this->run, $this->replay, $this->attempts());
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessInboundDeliveryAction::class)->fail($this->deliveryId, $exception);
    }
}
