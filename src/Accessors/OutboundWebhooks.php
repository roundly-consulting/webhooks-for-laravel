<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Accessors;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use RoundlyConsulting\Webhooks\DataTransferObjects\CreatedOutboundEndpoint;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\RedeliveryResult;
use RoundlyConsulting\Webhooks\DataTransferObjects\RotatedSecret;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OwnerEndpoints;
use RoundlyConsulting\Webhooks\Support\OwnerKeys;
use RoundlyConsulting\Webhooks\WebhooksManager;
use SensitiveParameter;

/**
 * `Webhooks::outbound()` / `Webhooks::for($owner)->outbound()` — outbound endpoints, sending,
 * redelivery and the delivery log. Scoped to an owner, every endpoint and delivery handed in
 * must belong to it (another owner's → `EndpointNotFoundException`).
 */
final readonly class OutboundWebhooks
{
    public function __construct(
        private WebhooksManager $manager,
        private ?Model $owner = null,
    ) {}

    /**
     * @param  list<string>  $events  exact types, `prefix.*` or `*`
     * @param  array<string, string>  $headers  custom headers sent with every delivery
     */
    public function create(
        #[SensitiveParameter] string $url,
        array $events = ['*'],
        ?string $name = null,
        #[SensitiveParameter] array $headers = [],
        string $scheme = 'default',
        ?OutboundEndpointOptions $options = null,
        ?Model $owner = null,
    ): CreatedOutboundEndpoint {
        return $this->manager->createOutboundEndpoint($url, $events, $name, $headers, $scheme, $options, $this->owner ?? $owner);
    }

    public function update(OutboundEndpoint $endpoint, OutboundEndpointChanges $changes): OutboundEndpoint
    {
        return $this->manager->updateOutboundEndpoint($this->guard($endpoint), $changes);
    }

    public function enable(OutboundEndpoint $endpoint): OutboundEndpoint
    {
        return $this->manager->setOutboundEndpointState($this->guard($endpoint), true);
    }

    public function disable(OutboundEndpoint $endpoint): OutboundEndpoint
    {
        return $this->manager->setOutboundEndpointState($this->guard($endpoint), false);
    }

    public function delete(OutboundEndpoint $endpoint): void
    {
        $this->manager->deleteOutboundEndpoint($this->guard($endpoint));
    }

    public function rotateSecret(OutboundEndpoint $endpoint, ?CarbonInterface $grace = null): RotatedSecret
    {
        return $this->manager->rotateOutboundSecret($this->guard($endpoint), $grace);
    }

    /**
     * @throws EndpointNotFoundException
     */
    public function find(int|string $id): OutboundEndpoint
    {
        $endpoint = $this->query()->find($id);

        return $endpoint instanceof OutboundEndpoint ? $endpoint : throw EndpointNotFoundException::make();
    }

    /**
     * @return Collection<int, OutboundEndpoint>
     */
    public function endpoints(): Collection
    {
        return $this->query()->orderBy('id')->get()->loadMissing('owner');
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     */
    public function send(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload): OutboundDelivery
    {
        return $this->manager->send($this->guard($endpoint), $eventType, $payload);
    }

    /**
     * Send a finished delivery again (same `webhook-id`, a new series of attempts).
     */
    public function redeliver(OutboundDelivery $delivery): OutboundDelivery
    {
        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof OutboundEndpoint) {
            throw EndpointNotFoundException::make();
        }

        $this->guard($endpoint);

        return $this->manager->redeliver($delivery);
    }

    /**
     * After an outage: redeliver every failed delivery of the endpoint (created since `$since`;
     * with `$includeCancelled`, the ones its disable cancelled too). Deliveries whose body was
     * pruned are skipped and counted. Enable the endpoint first.
     */
    public function redeliverFailed(OutboundEndpoint $endpoint, ?CarbonInterface $since = null, bool $includeCancelled = false): RedeliveryResult
    {
        return $this->manager->redeliverFailed($this->guard($endpoint), $since, $includeCancelled);
    }

    /**
     * What `webhooks:retry` runs: dispatch due, parked and lost deliveries.
     */
    public function dispatchDue(): int
    {
        return $this->manager->dispatchDue();
    }

    /**
     * @return Builder<OutboundDelivery>
     */
    public function deliveries(?OutboundEndpoint $endpoint = null): Builder
    {
        $query = ModelSeams::outboundDelivery()::query();

        if ($endpoint !== null) {
            return $query->where('endpoint_id', $this->guard($endpoint)->getKey());
        }

        return $this->owner === null ? $query : $query->whereIn('endpoint_id', $this->query()->select('id'));
    }

    /**
     * @return Collection<int, OutboundAttempt>
     */
    public function attempts(OutboundDelivery $delivery): Collection
    {
        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof OutboundEndpoint) {
            throw EndpointNotFoundException::make();
        }

        $this->guard($endpoint);

        return ModelSeams::outboundAttempt()::query()
            ->where('delivery_id', $delivery->getKey())
            ->orderBy('series')->orderBy('number')
            ->get();
    }

    /**
     * @return Builder<OutboundEndpoint>
     */
    private function query(): Builder
    {
        $query = ModelSeams::outboundEndpoint()::query();

        return $this->owner === null ? $query : OwnerEndpoints::scope($query, $this->owner);
    }

    /**
     * @template TEndpoint of OutboundEndpoint
     *
     * @param  TEndpoint  $endpoint
     * @return TEndpoint
     *
     * @throws EndpointNotFoundException
     */
    private function guard(OutboundEndpoint $endpoint): OutboundEndpoint
    {
        if ($this->owner !== null && ! OwnerKeys::owns($this->owner, $endpoint)) {
            throw EndpointNotFoundException::make();
        }

        return $endpoint;
    }
}
