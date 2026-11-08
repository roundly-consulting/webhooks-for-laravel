<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * What a scheme signs for one attempt: the delivery id, this attempt's timestamp and the exact
 * body bytes.
 */
final readonly class OutgoingWebhook
{
    public function __construct(
        public string $id,
        public CarbonImmutable $timestamp,
        public string $body,
        public string $eventType,
    ) {}
}
