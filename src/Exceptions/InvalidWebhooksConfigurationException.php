<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

/**
 * A configuration the package cannot run with: a class that does not implement its contract,
 * or an owner key that does not match `webhooks.key_type`.
 */
final class InvalidWebhooksConfigurationException extends WebhooksException
{
    /**
     * @param  class-string  $contract
     */
    public static function notAnImplementation(string $key, string $contract): self
    {
        return new self(self::translated('not_an_implementation', ['key' => $key, 'contract' => class_basename($contract)]));
    }

    public static function keyTypeMismatch(string $ownerBasename, string $keyType): self
    {
        return new self(self::translated('key_type_mismatch', ['owner' => $ownerBasename, 'type' => $keyType]));
    }
}
