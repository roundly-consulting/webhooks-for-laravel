<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Every package model's "now" is UTC: Eloquent's timestamps and soft deletes. Read back through
 * the UTC cast, a host on `Europe/Bratislava` stores and reads the same instants as one on UTC.
 * (A local copy of events-for-laravel's concern.)
 */
trait StoresUtcTimestamps
{
    public function freshTimestamp(): CarbonInterface
    {
        return Date::now('UTC')->startOfSecond();
    }
}
