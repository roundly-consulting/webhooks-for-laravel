<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Accessors;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Webhooks\DataTransferObjects\CreatedInboundEndpoint;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\RotatedSecret;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OwnerEndpoints;
use RoundlyConsulting\Webhooks\Support\OwnerKeys;
use RoundlyConsulting\Webhooks\WebhooksManager;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Webhooks::inbound()` / `Webhooks::for($owner)->inbound()` — inbound endpoints and their
 * deliveries. Scoped to an owner, every endpoint and delivery handed in must belong to it
 * (another owner's → `EndpointNotFoundException`), and `create()` sets the owner.
 */
final readonly class InboundWebhooks
{
    public function __construct(
        private WebhooksManager $manager,
        private ?Model $owner = null,
    ) {}

    public function create(
        ?string $handler = null,
        string $scheme = 'default',
        ?string $name = null,
        #[SensitiveParameter] ?string $secret = null,
        ?InboundEndpointOptions $options = null,
        ?Model $owner = null,
    ): CreatedInboundEndpoint {
        return $this->manager->createInboundEndpoint($this->owner ?? $owner, $handler, $scheme, $name, $secret, $options);
    }

    public function update(InboundEndpoint $endpoint, InboundEndpointChanges $changes): InboundEndpoint
    {
        return $this->manager->updateInboundEndpoint($this->guard($endpoint), $changes);
    }

    public function enable(InboundEndpoint $endpoint): InboundEndpoint
    {
        return $this->manager->setInboundEndpointState($this->guard($endpoint), true);
    }

    public function disable(InboundEndpoint $endpoint): InboundEndpoint
    {
        return $this->manager->setInboundEndpointState($this->guard($endpoint), false);
    }

    public function delete(InboundEndpoint $endpoint): void
    {
        $this->manager->deleteInboundEndpoint($this->guard($endpoint));
    }

    /**
     * A new secret (shown once); the old one keeps verifying until the grace ends. Pass
     * `secret:` to import the sender's new secret instead of generating one.
     */
    public function rotateSecret(InboundEndpoint $endpoint, ?CarbonInterface $grace = null, #[SensitiveParameter] ?string $secret = null): RotatedSecret
    {
        return $this->manager->rotateInboundSecret($this->guard($endpoint), $grace, $secret);
    }

    /**
     * @throws EndpointNotFoundException
     */
    public function find(int|string $id): InboundEndpoint
    {
        $endpoint = $this->query()->find($id);

        return $endpoint instanceof InboundEndpoint ? $endpoint : throw EndpointNotFoundException::make();
    }

    /**
     * @return Collection<int, InboundEndpoint>
     */
    public function endpoints(): Collection
    {
        return $this->query()->orderBy('id')->get()->loadMissing('owner');
    }

    /**
     * Run the inbound pipeline — what the package's route calls. Mount your own route with it.
     */
    public function receive(string $key, Request $request): Response
    {
        return $this->manager->receive($key, $request);
    }

    /**
     * Verify a request's signature only: no size check, no dedupe, nothing stored.
     */
    public function verify(InboundEndpoint $endpoint, Request $request): VerifiedWebhook
    {
        return $this->manager->verifyInbound($this->guard($endpoint), $request);
    }

    /**
     * Run the handler again with the stored payload, flagged as a replay.
     */
    public function replay(InboundDelivery $delivery): InboundDelivery
    {
        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof InboundEndpoint) {
            throw EndpointNotFoundException::make();
        }

        $this->guard($endpoint);

        return $this->manager->replay($delivery);
    }

    /**
     * @return Builder<InboundDelivery>
     */
    public function deliveries(?InboundEndpoint $endpoint = null): Builder
    {
        $query = ModelSeams::inboundDelivery()::query();

        if ($endpoint !== null) {
            return $query->where('endpoint_id', $this->guard($endpoint)->getKey());
        }

        return $this->owner === null ? $query : $query->whereIn('endpoint_id', $this->query()->select('id'));
    }

    /**
     * @return Builder<InboundEndpoint>
     */
    private function query(): Builder
    {
        $query = ModelSeams::inboundEndpoint()::query();

        return $this->owner === null ? $query : OwnerEndpoints::scope($query, $this->owner);
    }

    /**
     * @template TEndpoint of InboundEndpoint
     *
     * @param  TEndpoint  $endpoint
     * @return TEndpoint
     *
     * @throws EndpointNotFoundException
     */
    private function guard(InboundEndpoint $endpoint): InboundEndpoint
    {
        if ($this->owner !== null && ! OwnerKeys::owns($this->owner, $endpoint)) {
            throw EndpointNotFoundException::make();
        }

        return $endpoint;
    }
}
