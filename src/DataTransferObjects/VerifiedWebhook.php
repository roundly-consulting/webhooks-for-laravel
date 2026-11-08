<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;

/**
 * A scheme's verdict on an accepted request: the delivery id it carries (and whether the
 * signature covers it), the signed timestamp, the event type when the format names one, and
 * which secret matched.
 */
final readonly class VerifiedWebhook
{
    public function __construct(
        public ?string $deliveryId,
        public ?CarbonImmutable $timestamp,
        public ?string $eventType,
        public bool $idIsSigned,
        public SecretSlot $slot,
    ) {}
}
