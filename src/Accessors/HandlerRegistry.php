<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Accessors;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * `Webhooks::handlers()` — inbound handlers by alias: the configured ones (`webhooks.handlers`)
 * plus any `register()`ed. An endpoint stores the alias, never a class name. Scoped per
 * request/job (Octane-safe).
 */
final class HandlerRegistry
{
    /** @var array<string, class-string<InboundHandler>> */
    private array $registered = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<InboundHandler>  $handler
     *
     * @throws UnknownHandlerException for a class that is not a handler
     */
    public function register(string $alias, string $handler): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $alias) !== 1) {
            throw UnknownHandlerException::named($alias);
        }

        if (! is_subclass_of($handler, InboundHandler::class)) {
            throw UnknownHandlerException::notAHandler($alias);
        }

        $this->registered[$alias] = $handler;
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        $aliases = array_keys([...WebhooksConfig::handlers(), ...$this->registered]);
        sort($aliases);

        return array_map(strval(...), $aliases);
    }

    public function has(string $alias): bool
    {
        return isset($this->registered[$alias]) || isset(WebhooksConfig::handlers()[$alias]);
    }

    /**
     * @throws UnknownHandlerException
     */
    public function resolve(string $alias): InboundHandler
    {
        $class = $this->registered[$alias] ?? WebhooksConfig::handlers()[$alias] ?? throw UnknownHandlerException::named($alias);
        $handler = $this->container->make($class);

        // Checked again at run time: a container binding may hand back anything.
        if (! $handler instanceof InboundHandler) {
            throw UnknownHandlerException::notAHandler($alias);
        }

        return $handler;
    }
}
