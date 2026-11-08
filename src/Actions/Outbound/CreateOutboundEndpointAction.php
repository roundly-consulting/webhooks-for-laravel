<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions\Outbound;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\CreatedOutboundEndpoint;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\Exceptions\DestinationBlockedException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Support\EventTypes;
use RoundlyConsulting\Webhooks\Support\HeaderRules;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OutboundOptions;
use RoundlyConsulting\Webhooks\Support\OutboundUrls;
use RoundlyConsulting\Webhooks\Support\OwnerKeys;
use RoundlyConsulting\Webhooks\Support\Secrets;
use SensitiveParameter;

/**
 * Create an outbound endpoint: the URL is judged by the SSRF guard, the subscriptions and custom
 * headers are validated, and a signing secret is generated (shown once).
 */
final readonly class CreateOutboundEndpointAction
{
    public function __construct(
        private OutboundUrls $urls,
        private SchemeRegistry $schemes,
    ) {}

    /**
     * @param  list<string>  $events
     * @param  array<string, string>  $headers
     *
     * @throws DestinationBlockedException|InvalidEndpointException|UnknownSchemeException
     */
    public function execute(
        #[SensitiveParameter] string $url,
        array $events = ['*'],
        ?string $name = null,
        #[SensitiveParameter] array $headers = [],
        string $scheme = 'default',
        ?OutboundEndpointOptions $options = null,
        ?Model $owner = null,
    ): CreatedOutboundEndpoint {
        if (! $this->schemes->has($scheme)) {
            throw UnknownSchemeException::named($scheme);
        }

        if ($owner !== null) {
            OwnerKeys::assertFits($owner);
        }

        if ($name !== null && mb_strlen($name) > 191) {
            throw InvalidEndpointException::option('name');
        }

        $subscriptions = EventTypes::subscriptions($events);
        $validHeaders = HeaderRules::validate($headers);
        $judged = $this->urls->judge($url);
        $secret = Secrets::generate();
        $class = ModelSeams::outboundEndpoint();

        $endpoint = new $class;
        $endpoint->forceFill([
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'name' => $name,
            'url' => $judged['url'],
            'url_host' => $judged['host'],
            'signature_scheme' => $scheme,
            'secret' => $secret,
            'event_types' => $subscriptions,
            'headers' => $validHeaders === [] ? null : $validHeaders,
            'enabled' => true,
            'consecutive_failures' => 0,
            ...($options === null ? [] : OutboundOptions::columns($options)),
        ])->save();

        return new CreatedOutboundEndpoint($endpoint, $secret);
    }
}
