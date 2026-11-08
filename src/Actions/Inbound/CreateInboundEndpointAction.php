<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\CreatedInboundEndpoint;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Support\InboundOptions;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OwnerKeys;
use RoundlyConsulting\Webhooks\Support\Secrets;
use RoundlyConsulting\Webhooks\Support\UrlKeys;
use SensitiveParameter;

/**
 * Create an inbound endpoint: an unguessable URL key, a secret (generated, or the sender's
 * imported verbatim) shown once, the scheme and the handler alias.
 */
final readonly class CreateInboundEndpointAction
{
    public function __construct(
        private SchemeRegistry $schemes,
        private HandlerRegistry $handlers,
    ) {}

    /**
     * @throws UnknownHandlerException|UnknownSchemeException|InvalidEndpointException
     */
    public function execute(
        ?Model $owner = null,
        ?string $handler = null,
        string $scheme = 'default',
        ?string $name = null,
        #[SensitiveParameter] ?string $secret = null,
        ?InboundEndpointOptions $options = null,
    ): CreatedInboundEndpoint {
        if ($handler !== null && ! $this->handlers->has($handler)) {
            throw UnknownHandlerException::named($handler);
        }

        if (! $this->schemes->has($scheme)) {
            throw UnknownSchemeException::named($scheme);
        }

        if ($owner !== null) {
            OwnerKeys::assertFits($owner);
        }

        if ($name !== null && mb_strlen($name) > 191) {
            throw InvalidEndpointException::option('name');
        }

        $secret = $secret === null ? Secrets::generate() : Secrets::imported($secret, $this->schemes->secretEncoding($scheme));
        $class = ModelSeams::inboundEndpoint();

        $endpoint = new $class;
        $endpoint->forceFill([
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'name' => $name,
            'url_key' => UrlKeys::generate(),
            'signature_scheme' => $scheme,
            'secret' => $secret,
            'handler' => $handler,
            'enabled' => true,
            ...($options === null ? [] : InboundOptions::columns($options)),
        ])->save();

        return new CreatedInboundEndpoint($endpoint, $endpoint->url(), $secret);
    }
}
