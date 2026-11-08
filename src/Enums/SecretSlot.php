<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Which of an endpoint's secrets matched: the current one or the previous one (rotation grace).
 */
#[TranslatedLabels('webhooks::enums.secret_slot')]
enum SecretSlot: string
{
    use Helpers;

    case Current = 'current';
    case Previous = 'previous';
}
