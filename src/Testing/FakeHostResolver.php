<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Testing;

use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Testing\FakeHostResolver as SafeFetchResolver;

/**
 * DNS answers for a host test of outbound webhooks — a thin alias of safe-fetch's fake resolver
 * (no second resolver): names it was not given do not resolve, so a test never reaches real DNS.
 *
 *     FakeHostResolver::map(['hooks.example.com' => '93.184.216.34'])->install();
 */
final class FakeHostResolver
{
    /**
     * @param  array<string, string|list<string>>  $records
     */
    private function __construct(private array $records) {}

    /**
     * @param  array<string, string|list<string>>  $records
     */
    public static function map(array $records): self
    {
        return new self($records);
    }

    /**
     * Install the answers; returns safe-fetch's resolver (`assertLookedUp()`, `lookups()`, …).
     */
    public function install(): SafeFetchResolver
    {
        return SafeFetch::fakeDns($this->records);
    }
}
