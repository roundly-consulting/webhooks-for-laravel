<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;

/**
 * Puts the package's jobs on the configured connection and queues. Every outbound dispatch
 * writes a fresh dispatch token first: the job claims the row with it, so a superseded or
 * duplicated job finds nothing to do.
 *
 * @internal
 */
final readonly class Queues
{
    public function __construct(private Dispatcher $bus) {}

    public function inbound(InboundDelivery $delivery, bool $replay = false): void
    {
        $job = new ProcessInboundDelivery(Keys::of($delivery), $delivery->runs, $replay);
        $job->onConnection(WebhooksConfig::queueConnection())->onQueue(WebhooksConfig::inboundQueue());

        $this->bus->dispatch($job);
    }

    /**
     * Dispatch the delivery's next attempt (after `$delay` seconds), after the current
     * transaction commits.
     */
    public function outbound(OutboundDelivery $delivery, int $delay = 0): void
    {
        $token = strtolower((string) Str::ulid());

        $delivery->forceFill(['dispatch_token' => $token, 'dispatched_at' => Clock::now()])->save();

        $job = new DeliverWebhook(Keys::of($delivery), $token);
        $job->onConnection(WebhooksConfig::queueConnection())->onQueue(WebhooksConfig::outboundQueue())->afterCommit();

        if ($delay > 0) {
            $job->delay($delay);
        }

        $this->bus->dispatch($job);
    }
}
