<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Support\Clock;
use Throwable;

/**
 * A datetime column stored as a UTC `Y-m-d H:i:s` string and read back as a UTC
 * `CarbonImmutable`, whatever `app.timezone` says.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Clock::utc($value);
        }

        $value = is_scalar($value) ? (string) $value : '';

        try {
            $parsed = CarbonImmutable::createFromFormat(Clock::FORMAT, substr($value, 0, 19), 'UTC');
        } catch (Throwable) {
            $parsed = null;
        }

        return $parsed instanceof CarbonImmutable ? $parsed : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value instanceof DateTimeInterface => Clock::format($value),
            is_string($value) && $value !== '' => Clock::format(CarbonImmutable::parse($value, 'UTC')),
            default => null,
        };
    }
}
