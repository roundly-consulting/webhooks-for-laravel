<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Accessors;

use Closure;
use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * `Webhooks::schemes()` — signature schemes by name: the configured ones (`webhooks.schemes`)
 * plus any added with `extend()`. One registry per process: register extensions in a service
 * provider's `boot()` and they hold for every request, job and Octane worker request. A
 * registration made inside a request or job lasts for the rest of the process too (like
 * `Gate::define()`); `forget()` removes one (tests).
 */
final class SchemeRegistry
{
    /** @var array<string, class-string<SignatureScheme>|Closure(): SignatureScheme> */
    private array $extensions = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<SignatureScheme>|Closure(): SignatureScheme  $scheme
     *
     * @throws UnknownSchemeException for a class that is not a scheme
     */
    public function extend(string $name, string|Closure $scheme): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $name) !== 1) {
            throw UnknownSchemeException::named($name);
        }

        if (is_string($scheme) && ! is_subclass_of($scheme, SignatureScheme::class)) {
            throw UnknownSchemeException::notAScheme($name);
        }

        $this->extensions[$name] = $scheme;
    }

    /**
     * Drop a scheme added with `extend()`. A configured scheme stays.
     */
    public function forget(string $name): void
    {
        unset($this->extensions[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys([...WebhooksConfig::schemes(), ...$this->extensions]);
        sort($names);

        return array_map(strval(...), $names);
    }

    public function has(string $name): bool
    {
        return isset($this->extensions[$name]) || isset(WebhooksConfig::schemes()[$name]);
    }

    /**
     * How a scheme reads an imported secret, as far as the package knows: Standard Webhooks for
     * the default scheme, the string's own bytes for anything else (a custom scheme picks its
     * encoding per call).
     *
     * @internal
     */
    public function secretEncoding(string $name): SecretEncoding
    {
        try {
            return $this->resolve($name) instanceof DefaultScheme ? SecretEncoding::StandardWebhooks : SecretEncoding::Raw;
        } catch (UnknownSchemeException) {
            return SecretEncoding::Raw;
        }
    }

    /**
     * @throws UnknownSchemeException
     */
    public function resolve(string $name): SignatureScheme
    {
        $scheme = $this->extensions[$name] ?? WebhooksConfig::schemes()[$name] ?? null;

        if ($scheme === null) {
            throw UnknownSchemeException::named($name);
        }

        $resolved = $scheme instanceof Closure ? $scheme() : $this->container->make($scheme);

        if (! $resolved instanceof SignatureScheme) {
            throw UnknownSchemeException::notAScheme($name);
        }

        return $resolved;
    }
}
