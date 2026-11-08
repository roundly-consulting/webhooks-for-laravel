<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How serious a `webhooks:doctor` finding is: an error fails the command (exit 1), a warning
 * does not.
 */
enum FindingLevel: string
{
    use Helpers;

    case Error = 'error';
    case Warning = 'warning';
}
