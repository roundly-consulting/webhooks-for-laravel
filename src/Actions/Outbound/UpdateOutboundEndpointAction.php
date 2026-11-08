<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\Exceptions\DestinationBlockedException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\EventTypes;
use RoundlyConsulting\Webhooks\Support\HeaderRules;
use RoundlyConsulting\Webhooks\Support\OutboundOptions;
use RoundlyConsulting\Webhooks\Support\OutboundUrls;

/**
 * Change an outbound endpoint. A new URL is judged by the SSRF guard like a new endpoint's.
 */
final readonly class UpdateOutboundEndpointAction
{
    private const array CLEARABLE = ['name', 'description', 'headers', 'rate_limit_per_minute', 'scheme_options'];

    public function __construct(
        private OutboundUrls $urls,
        private SchemeRegistry $schemes,
    ) {}

    /**
     * @throws DestinationBlockedException|InvalidEndpointException|UnknownSchemeException
     */
    public function execute(OutboundEndpoint $endpoint, OutboundEndpointChanges $changes): OutboundEndpoint
    {
        $columns = [];

        foreach ($changes->clear as $field) {
            $columns[$field] = in_array($field, self::CLEARABLE, true) ? null : throw InvalidEndpointException::option($field);
        }

        if ($changes->url !== null) {
            $judged = $this->urls->judge($changes->url);
            $columns['url'] = $judged['url'];
            $columns['url_host'] = $judged['host'];
        }

        if ($changes->events !== null) {
            $columns['event_types'] = EventTypes::subscriptions($changes->events);
        }

        if ($changes->name !== null) {
            $columns['name'] = mb_strlen($changes->name) <= 191 ? $changes->name : throw InvalidEndpointException::option('name');
        }

        if ($changes->headers !== null) {
            $headers = HeaderRules::validate($changes->headers);
            $columns['headers'] = $headers === [] ? null : $headers;
        }

        if ($changes->scheme !== null) {
            $columns['signature_scheme'] = $this->schemes->has($changes->scheme) ? $changes->scheme : throw UnknownSchemeException::named($changes->scheme);
        }

        if ($changes->options !== null) {
            $columns = [...$columns, ...OutboundOptions::columns($changes->options)];
        }

        $endpoint->forceFill($columns)->save();

        return $endpoint;
    }
}
