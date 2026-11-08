<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\DataTransferObjects\RotatedSecret;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\SecretRotationException;
use SensitiveParameter;

/**
 * previous ← current, current ← new, previous expires at the grace end — under a row lock, so
 * two concurrent rotations cannot both keep a "previous" (the second sees the first's result).
 * Rotating again during a grace drops the oldest secret at once.
 *
 * @internal
 */
final class SecretRotation
{
    /**
     * @throws SecretRotationException
     */
    public static function rotate(Model $endpoint, ?CarbonInterface $grace = null, #[SensitiveParameter] ?string $secret = null, SecretEncoding $encoding = SecretEncoding::Raw): RotatedSecret
    {
        $now = Clock::now();
        $graceEndsAt = $grace === null ? $now->addSeconds(WebhooksConfig::graceSeconds()) : Clock::utc($grace);

        if ($graceEndsAt->isBefore($now->subSecond())) {
            throw SecretRotationException::graceInThePast();
        }

        if ($graceEndsAt->getTimestamp() - $now->getTimestamp() > WebhooksConfig::maxGraceSeconds()) {
            throw SecretRotationException::graceTooLong(WebhooksConfig::maxGraceSeconds());
        }

        $new = $secret === null ? Secrets::generate() : Secrets::imported($secret, $encoding);

        $endpoint->getConnection()->transaction(static function () use ($endpoint, $new, $graceEndsAt, $now): void {
            $locked = $endpoint->newQuery()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->getAttribute('secret');

            $locked->forceFill([
                'previous_secret' => is_string($current) && $current !== '' && $graceEndsAt->isAfter($now) ? $current : null,
                'previous_secret_expires_at' => $graceEndsAt->isAfter($now) ? $graceEndsAt : null,
                'secret' => $new,
                'secret_rotated_at' => $now,
            ])->save();

            $endpoint->setRawAttributes($locked->getAttributes(), true);
        });

        return new RotatedSecret($new, $graceEndsAt);
    }
}
