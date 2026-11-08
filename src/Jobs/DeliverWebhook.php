<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\HttpClientRateLimits\Jobs\Middleware\HandlesRateLimitRelease;
use RoundlyConsulting\Sentinel\Jobs\Middleware\Idempotent;
use RoundlyConsulting\Webhooks\Actions\Outbound\AttemptDeliveryAction;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use Throwable;

/**
 * One outbound attempt. Delivery attempts are counted in the database, not by the queue:
 * `$tries = 0`, because every throttle release counts as a queue attempt (a worker's default
 * `--tries=1` would otherwise fail the job on its first re-run). An exception during the attempt is
 * recorded as an `unexpected` attempt by the action; one before it claimed the row (the database
 * down) stops after `$maxExceptions` and hands the row to the sweep.
 */
final class DeliverWebhook implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout;

    public function __construct(
        public readonly int|string $deliveryId,
        public readonly string $dispatchToken,
    ) {
        $this->timeout = WebhooksConfig::totalTimeout() + 20;
    }

    /**
     * Outermost: at most once per dispatch. Inner: a throttle release ends the attempt cleanly
     * (and the idempotency key is given back, so the released job runs again).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new Idempotent("webhooks:out:{$this->deliveryId}:{$this->dispatchToken}", scope: 'webhooks'),
            new HandlesRateLimitRelease,
        ];
    }

    /**
     * Horizon tags: the package, the delivery. (The endpoint would cost a query per dispatch.)
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['webhooks', 'webhooks:outbound', "webhooks:delivery:{$this->deliveryId}"];
    }

    public function displayName(): string
    {
        return 'webhooks: deliver';
    }

    public function handle(AttemptDeliveryAction $action): void
    {
        $action->execute($this->deliveryId, $this->dispatchToken, $this);
    }

    public function failed(?Throwable $exception): void
    {
        app(AttemptDeliveryAction::class)->abandon($this->deliveryId, $this->dispatchToken);
    }
}
