<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Why an inbound request was refused. The code is what the problem response carries.
 */
#[TranslatedLabels('webhooks::enums.rejection_reason')]
enum RejectionReason: string
{
    use Helpers;

    case NotFound = 'not_found';
    case EndpointDisabled = 'endpoint_disabled';
    case RateLimited = 'rate_limited';
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedContentType = 'unsupported_content_type';
    case MissingSignature = 'missing_signature';
    case InvalidSignature = 'invalid_signature';
    case MissingTimestamp = 'missing_timestamp';
    case InvalidTimestamp = 'invalid_timestamp';
    case StaleTimestamp = 'stale_timestamp';
    case MissingId = 'missing_id';
    case KeyNotBound = 'key_not_bound';
    case UnknownScheme = 'unknown_scheme';
    case Replayed = 'replayed';
    case InvalidPayload = 'invalid_payload';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case InProgress = 'in_progress';
    case Conflict = 'conflict';
    case SanitizerFailed = 'sanitizer_failed';

    /**
     * The HTTP status a rejection answers with.
     */
    public function status(): int
    {
        return match ($this) {
            self::NotFound, self::EndpointDisabled => 404,
            self::RateLimited => 429,
            self::PayloadTooLarge => 413,
            self::UnsupportedContentType => 415,
            self::InvalidPayload => 400,
            self::IdempotencyKeyReused, self::InProgress, self::Conflict => 409,
            self::SanitizerFailed => 500,
            self::MissingSignature, self::InvalidSignature, self::MissingTimestamp, self::InvalidTimestamp,
            self::StaleTimestamp, self::MissingId, self::KeyNotBound, self::UnknownScheme, self::Replayed => 401,
        };
    }

    /**
     * The code a client sees. A disabled endpoint reads exactly like an unknown one.
     */
    public function publicCode(): string
    {
        return $this === self::EndpointDisabled ? self::NotFound->value : $this->value;
    }
}
