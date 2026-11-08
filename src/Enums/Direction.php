<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Which way a webhook travels.
 */
#[TranslatedLabels('webhooks::enums.direction')]
enum Direction: string
{
    use Helpers;

    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
