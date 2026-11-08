<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonImmutable;
use Closure;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Exceptions\WebhookSignatureException;

/**
 * A scheme's verdict on an accepted request: the delivery id it carries (and whether the
 * signature covers it), the signed timestamp, the event type when the format names one, which
 * secret matched, and whether the scheme itself refuses a second use of this exact request (an
 * RFC 9421 nonce) — then no body guard is needed for an unsigned id.
 *
 * `commit`, when set, records that one use: the pipeline calls it only once the delivery is
 * stored — before answering and before anything acts on it — so a request refused on the way
 * (400, 500) can be resent unchanged. It throws `WebhookSignatureException` (`replayed`,
 * `stale_timestamp`) when another copy committed first or the window has closed.
 */
final readonly class VerifiedWebhook
{
    /**
     * @param  (Closure(): void)|null  $commit
     */
    public function __construct(
        public ?string $deliveryId,
        public ?CarbonImmutable $timestamp,
        public ?string $eventType,
        public bool $idIsSigned,
        public SecretSlot $slot,
        public bool $replayProtected = false,
        public ?Closure $commit = null,
    ) {}

    /**
     * Record the request's single use now (nothing to record: nothing happens), and the same
     * verdict without anything left to commit.
     *
     * @throws WebhookSignatureException
     */
    public function committed(): self
    {
        if ($this->commit !== null) {
            ($this->commit)();
        }

        return new self($this->deliveryId, $this->timestamp, $this->eventType, $this->idIsSigned, $this->slot, $this->replayProtected);
    }
}
