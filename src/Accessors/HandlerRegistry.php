<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Accessors;

use Closure;
use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Support\ClosureHandler;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * `Webhooks::handlers()` — inbound handlers by alias: the configured ones (`webhooks.handlers`)
 * plus any `register()`ed (a class or a closure). An endpoint stores the alias, never a class name. One registry per
 * process: register in a service provider's `boot()` and the handler runs for every job a worker
 * processes. A registration made inside a request or job lasts for the rest of the process too;
 * `forget()` removes one (tests).
 */
final class HandlerRegistry
{
    /** @var array<string, class-string<InboundHandler>|Closure(InboundWebhook): mixed> */
    private array $registered = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<InboundHandler>|Closure(InboundWebhook): mixed  $handler
     *
     * @throws UnknownHandlerException for a class that is not a handler
     */
    public function register(string $alias, string|Closure $handler): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $alias) !== 1) {
            throw UnknownHandlerException::named($alias);
        }

        if (is_string($handler) && ! is_subclass_of($handler, InboundHandler::class)) {
            throw UnknownHandlerException::notAHandler($alias);
        }

        $this->registered[$alias] = $handler;
    }

    /**
     * Drop a handler added with `register()`. A configured handler stays.
     */
    public function forget(string $alias): void
    {
        unset($this->registered[$alias]);
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

        if ($class instanceof Closure) {
            return new ClosureHandler($class);
        }

        $handler = $this->container->make($class);

        // Checked again at run time: a container binding may hand back anything.
        if (! $handler instanceof InboundHandler) {
            throw UnknownHandlerException::notAHandler($alias);
        }

        return $handler;
    }
}
