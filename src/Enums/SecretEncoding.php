<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How a stored secret string becomes HMAC key bytes — the scheme's choice, never the import's.
 */
#[TranslatedLabels('webhooks::enums.secret_encoding')]
enum SecretEncoding: string
{
    use Helpers;

    case StandardWebhooks = 'standard_webhooks';
    case Raw = 'raw';
}
