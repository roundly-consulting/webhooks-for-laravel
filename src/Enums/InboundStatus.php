<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where an inbound delivery is: stored, queued, being handled, done or failed.
 */
#[TranslatedLabels('webhooks::enums.inbound_status')]
enum InboundStatus: string
{
    use Helpers;

    case Received = 'received';
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
