<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;

/**
 * UTC "now" and the one stored datetime format.
 *
 * @internal
 */
final class Clock
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->utc();
    }

    public static function utc(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->utc();
    }

    public static function format(DateTimeInterface $instant): string
    {
        return self::utc($instant)->format(self::FORMAT);
    }
}
