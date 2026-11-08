<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;

/**
 * An owner's key must fit the `owner_id` column `webhooks.key_type` created — checked before any
 * write, so a uuid team on a bigint install fails loudly instead of being truncated.
 *
 * @internal
 */
final class OwnerKeys
{
    public static function assertFits(Model $owner): void
    {
        $key = $owner->getKey();
        $type = WebhooksConfig::keyType();

        $fits = match ($type) {
            KeyType::BigInt => is_int($key) || (is_string($key) && preg_match('/^[0-9]{1,19}$/D', $key) === 1),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };

        if (! $fits) {
            throw InvalidWebhooksConfigurationException::keyTypeMismatch(class_basename($owner), $type->value);
        }
    }

    /**
     * Whether an endpoint row belongs to the owner.
     */
    public static function owns(Model $owner, Model $endpoint): bool
    {
        $type = $endpoint->getAttribute('owner_type');
        $id = $endpoint->getAttribute('owner_id');

        return $type === $owner->getMorphClass() && Keys::string($id) !== null && Keys::string($id) === Keys::string($owner->getKey());
    }
}
