<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

use RoundlyConsulting\Webhooks\Enums\RejectionReason;

/**
 * A scheme refused an inbound request. Custom schemes throw it with the reason that fits.
 */
final class WebhookSignatureException extends WebhooksException
{
    private RejectionReason $reason = RejectionReason::InvalidSignature;

    public static function because(RejectionReason $reason): self
    {
        $exception = new self(self::translated('signature_rejected', ['reason' => $reason->value]));
        $exception->reason = $reason;

        return $exception;
    }

    public function reason(): RejectionReason
    {
        return $this->reason;
    }
}
