<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where an outbound delivery is. Retrying rows wait for their next attempt.
 */
#[TranslatedLabels('webhooks::enums.outbound_status')]
enum OutboundStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Sending = 'sending';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
