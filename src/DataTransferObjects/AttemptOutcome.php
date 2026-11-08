<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use RoundlyConsulting\Webhooks\Enums\AttemptError;

/**
 * The result of one HTTP attempt, before it is classified.
 *
 * @internal
 */
final readonly class AttemptOutcome
{
    public function __construct(
        public ?int $status,
        public ?string $responseBody,
        public ?int $retryAfter,
        public ?string $remoteAddress,
        public ?AttemptError $error,
        public ?string $errorMessage,
        public bool $terminal,
        public int $durationMs,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
