<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Why an endpoint was disabled.
 */
#[TranslatedLabels('webhooks::enums.disable_reason')]
enum DisableReason: string
{
    use Helpers;

    case ConsecutiveFailures = 'consecutive_failures';
    case Gone = 'gone';
    case Manual = 'manual';
}
