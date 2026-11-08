<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Inbound;

use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointChanges;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\InboundOptions;

/**
 * Change an inbound endpoint's name, handler, scheme or overrides. The URL key and the secret
 * never change here (rotate the secret instead).
 */
final readonly class UpdateInboundEndpointAction
{
    private const array CLEARABLE = ['name', 'handler', 'rate_limit_per_minute', 'max_payload_bytes', 'allowed_content_types', 'tolerance_seconds', 'scheme_options'];

    public function __construct(
        private SchemeRegistry $schemes,
        private HandlerRegistry $handlers,
    ) {}

    /**
     * @throws UnknownHandlerException|UnknownSchemeException|InvalidEndpointException
     */
    public function execute(InboundEndpoint $endpoint, InboundEndpointChanges $changes): InboundEndpoint
    {
        $columns = [];

        foreach ($changes->clear as $field) {
            if (! in_array($field, self::CLEARABLE, true)) {
                throw InvalidEndpointException::option($field);
            }

            $columns[$field] = null;
        }

        if ($changes->name !== null) {
            $columns['name'] = mb_strlen($changes->name) > 191 ? throw InvalidEndpointException::option('name') : $changes->name;
        }

        if ($changes->handler !== null) {
            $columns['handler'] = $this->handlers->has($changes->handler) ? $changes->handler : throw UnknownHandlerException::named($changes->handler);
        }

        if ($changes->scheme !== null) {
            $columns['signature_scheme'] = $this->schemes->has($changes->scheme) ? $changes->scheme : throw UnknownSchemeException::named($changes->scheme);
        }

        if ($changes->options !== null) {
            $columns = [...$columns, ...InboundOptions::columns($changes->options)];
        }

        $endpoint->forceFill($columns)->save();

        return $endpoint;
    }
}
