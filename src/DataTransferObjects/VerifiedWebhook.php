<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;

/**
 * A scheme's verdict on an accepted request: the delivery id it carries (and whether the
 * signature covers it), the signed timestamp, the event type when the format names one, which
 * secret matched, and whether the scheme itself already refuses a second use of this exact
 * request (an RFC 9421 nonce) — then no body guard is needed for an unsigned id.
 */
final readonly class VerifiedWebhook
{
    public function __construct(
        public ?string $deliveryId,
        public ?CarbonImmutable $timestamp,
        public ?string $eventType,
        public bool $idIsSigned,
        public SecretSlot $slot,
        public bool $replayProtected = false,
    ) {}
}
