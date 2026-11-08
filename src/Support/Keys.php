<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A model's key, typed: every package table keys on a bigint, uuid or ulid.
 *
 * @internal
 */
final class Keys
{
    public static function of(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : throw new LogicException('A webhooks model has no key yet.');
    }

    public static function string(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? (string) $value : null;
    }
}
