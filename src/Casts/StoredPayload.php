<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored inbound payload: JSON, encrypted at rest while `webhooks.payloads.encrypt` is on. The
 * prefix (`enc:` / `raw:`) says how each row was written, so switching the setting never makes
 * an older row unreadable. Decodes to arrays only — never objects.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, array<array-key, mixed>|null>
 */
final class StoredPayload implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $json = StoredText::read($value);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return StoredText::write(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
