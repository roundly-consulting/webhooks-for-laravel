<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use RoundlyConsulting\Webhooks\Actions\ExampleWebhooksAction;

/**
 * REPLACE ME — the input of {@see ExampleWebhooksAction}.
 *
 * Actions take a DTO, never a shape array. Rename this alongside the action it feeds.
 */
final readonly class ExampleWebhooksData
{
    public function __construct(
        public string $name,
    ) {}
}
