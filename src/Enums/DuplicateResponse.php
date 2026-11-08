<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How a delivery seen before is answered: 200 with no new event, or 409.
 */
#[TranslatedLabels('webhooks::enums.duplicate_response')]
enum DuplicateResponse: string
{
    use Helpers;

    case Accept = 'accept';
    case Conflict = 'conflict';
}
