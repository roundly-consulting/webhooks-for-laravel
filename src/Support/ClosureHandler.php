<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Closure;
use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;

/**
 * A handler registered as a closure (`Webhooks::handlers()->register('billing', fn (InboundWebhook $w) => …)`).
 *
 * @internal
 */
final readonly class ClosureHandler implements InboundHandler
{
    /**
     * @param  Closure(InboundWebhook): mixed  $handler
     */
    public function __construct(private Closure $handler) {}

    public function handle(InboundWebhook $webhook): void
    {
        ($this->handler)($webhook);
    }
}
