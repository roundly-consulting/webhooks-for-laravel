<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;

/**
 * An outbound URL the SSRF guard refuses. The message reads the same for a blocked and an
 * unresolvable host (no internal-DNS oracle); `reason()` and `blockedBy()` are for operators.
 */
final class DestinationBlockedException extends WebhooksException
{
    private Reason $reason = Reason::Blocked;

    private ?string $blockedBy = null;

    public static function from(SafeFetchException $exception): self
    {
        $blocked = new self(self::translated('destination_blocked'), 0, $exception);
        $blocked->reason = $exception->reason();
        $blocked->blockedBy = $exception instanceof DestinationRejected ? $exception->blockedBy() : null;

        return $blocked;
    }

    public function reason(): Reason
    {
        return $this->reason;
    }

    public function blockedBy(): ?string
    {
        return $this->blockedBy;
    }

    /**
     * Whether the host merely did not resolve (it may later).
     */
    public function unresolved(): bool
    {
        return $this->getPrevious() instanceof DestinationUnavailable;
    }
}
