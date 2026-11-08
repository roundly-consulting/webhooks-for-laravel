<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Exceptions;

use RuntimeException;

/**
 * Every failure the package throws on purpose. Messages are translated
 * (`webhooks::exceptions.*`) and never carry a secret, a payload or a class name.
 */
abstract class WebhooksException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    protected static function translated(string $key, array $replace = []): string
    {
        $line = trans('webhooks::exceptions.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
