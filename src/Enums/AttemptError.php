<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\SafeFetch\Enums\Reason;

/**
 * Why an outbound attempt failed — our own codes, never a vendor's message.
 */
#[TranslatedLabels('webhooks::enums.attempt_error')]
enum AttemptError: string
{
    use Helpers;

    case HttpStatus = 'http_status';
    case RedirectNotFollowed = 'redirect_not_followed';
    case Timeout = 'timeout';
    case Connection = 'connection';
    case Tls = 'tls';
    case Unresolved = 'unresolved';
    case Blocked = 'blocked';
    case CannotPin = 'cannot_pin';
    case TooManyRedirects = 'too_many_redirects';
    case RedirectDowngrade = 'redirect_downgrade';
    case Signing = 'signing';
    case WorkerLost = 'worker_lost';
    case Unexpected = 'unexpected';

    /**
     * The attempt error for a safe-fetch verdict or transport failure.
     */
    public static function fromReason(Reason $reason): self
    {
        return match ($reason) {
            Reason::Unresolved => self::Unresolved,
            Reason::CannotPin => self::CannotPin,
            Reason::TooManyRedirects => self::TooManyRedirects,
            Reason::RedirectDowngrade => self::RedirectDowngrade,
            Reason::Timeout => self::Timeout,
            Reason::Tls => self::Tls,
            Reason::Connection, Reason::Protocol, Reason::Status, Reason::TooLarge => self::Connection,
            Reason::InvalidUrl, Reason::Scheme, Reason::Credentials, Reason::NonCanonicalHost, Reason::Port,
            Reason::Blocked, Reason::NotAllowed => self::Blocked,
        };
    }
}
