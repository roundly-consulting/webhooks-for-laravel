<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Drives a package model's primary key from `webhooks.primary_key_type`, matching the column the
 * migrations emitted. On `bigint` the model behaves as if it never used the trait. (A local copy
 * of events-for-laravel's concern; a toolkit extraction is an open fleet ask.)
 */
trait HasConfigurableKey
{
    use HasUniqueStringIds;

    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = $this->configuredKeyType() !== KeyType::BigInt;
    }

    /**
     * A time-ordered key of the configured type (`uuid7` or `ulid`).
     */
    public function newUniqueId(): string
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? strtolower((string) Str::ulid())
            : (string) Str::uuid7();
    }

    public function configuredKeyType(): KeyType
    {
        return WebhooksConfig::primaryKeyType();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? Str::isUlid($value)
            : Str::isUuid($value);
    }
}
