<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use JsonSerializable;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Accessors\InboundWebhooks;
use RoundlyConsulting\Webhooks\Accessors\OutboundWebhooks;
use RoundlyConsulting\Webhooks\Accessors\OwnerWebhooks;
use RoundlyConsulting\Webhooks\Accessors\SchemeRegistry;
use RoundlyConsulting\Webhooks\Actions\Inbound\CreateInboundEndpointAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\DeleteInboundEndpointAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\ReceiveWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\ReplayInboundDeliveryAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\RotateInboundSecretAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\SetInboundEndpointStateAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\UpdateInboundEndpointAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\VerifyInboundRequestAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\BroadcastWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\CreateOutboundEndpointAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\DeleteOutboundEndpointAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\DispatchDueDeliveriesAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\PingEndpointAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\RedeliverFailedDeliveriesAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\RedeliverWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\RotateOutboundSecretAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\SendWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\SetOutboundEndpointStateAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\UpdateOutboundEndpointAction;
use RoundlyConsulting\Webhooks\Actions\PruneAction;
use RoundlyConsulting\Webhooks\DataTransferObjects\CreatedInboundEndpoint;
use RoundlyConsulting\Webhooks\DataTransferObjects\CreatedOutboundEndpoint;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\PruneResult;
use RoundlyConsulting\Webhooks\DataTransferObjects\RedeliveryResult;
use RoundlyConsulting\Webhooks\DataTransferObjects\RotatedSecret;
use RoundlyConsulting\Webhooks\DataTransferObjects\VerifiedWebhook;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The package's public API: the root behind the `Webhooks` facade, injectable by its own
 * class-string. Every method resolves one action through the container, so a host override and
 * `Webhooks::fake()` both apply. The sub-accessors (`inbound()`, `outbound()`, `for($owner)`)
 * call back into the `@internal` methods below, so the fake sees every call they make too.
 *
 * Not final on purpose: `WebhooksFake` extends it.
 */
class WebhooksManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * Queue one event for one endpoint — signed, retried, throttled and SSRF-guarded. With `$id`
     * the send is idempotent: a repeat with the same id returns the first delivery.
     *
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     */
    public function send(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload, ?string $id = null): OutboundDelivery
    {
        return $this->container->make(SendWebhookAction::class)->execute($endpoint, $eventType, $payload, $id);
    }

    /**
     * Send to every enabled endpoint of the owner that subscribes to the type (no owner: the
     * owner-less, application-level endpoints only).
     *
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     * @return Collection<int, OutboundDelivery>
     */
    public function broadcast(string $eventType, array|Arrayable|JsonSerializable $payload, ?Model $owner = null): Collection
    {
        return $this->container->make(BroadcastWebhookAction::class)->execute($eventType, $payload, $owner);
    }

    /**
     * One owner's endpoints — any model; the scope is a boundary.
     */
    public function for(Model $owner): OwnerWebhooks
    {
        return new OwnerWebhooks($this, $owner);
    }

    public function inbound(): InboundWebhooks
    {
        return new InboundWebhooks($this);
    }

    public function outbound(): OutboundWebhooks
    {
        return new OutboundWebhooks($this);
    }

    public function schemes(): SchemeRegistry
    {
        return $this->container->make(SchemeRegistry::class);
    }

    public function handlers(): HandlerRegistry
    {
        return $this->container->make(HandlerRegistry::class);
    }

    public function prune(?PruneOptions $options = null): PruneResult
    {
        return $this->container->make(PruneAction::class)->execute($options);
    }

    /**
     * @internal what `inbound()->create()` calls
     */
    public function createInboundEndpoint(
        ?Model $owner,
        ?string $handler = null,
        string $scheme = 'default',
        ?string $name = null,
        #[SensitiveParameter] ?string $secret = null,
        ?InboundEndpointOptions $options = null,
    ): CreatedInboundEndpoint {
        return $this->container->make(CreateInboundEndpointAction::class)->execute($owner, $handler, $scheme, $name, $secret, $options);
    }

    /**
     * @internal what `inbound()->update()` calls
     */
    public function updateInboundEndpoint(InboundEndpoint $endpoint, InboundEndpointChanges $changes): InboundEndpoint
    {
        return $this->container->make(UpdateInboundEndpointAction::class)->execute($endpoint, $changes);
    }

    /**
     * @internal what `inbound()->enable()` / `disable()` call
     */
    public function setInboundEndpointState(InboundEndpoint $endpoint, bool $enabled): InboundEndpoint
    {
        return $this->container->make(SetInboundEndpointStateAction::class)->execute($endpoint, $enabled);
    }

    /**
     * @internal what `inbound()->delete()` calls
     */
    public function deleteInboundEndpoint(InboundEndpoint $endpoint): void
    {
        $this->container->make(DeleteInboundEndpointAction::class)->execute($endpoint);
    }

    /**
     * @internal what `inbound()->rotateSecret()` calls
     */
    public function rotateInboundSecret(InboundEndpoint $endpoint, ?CarbonInterface $grace = null, #[SensitiveParameter] ?string $secret = null): RotatedSecret
    {
        return $this->container->make(RotateInboundSecretAction::class)->execute($endpoint, $grace, $secret);
    }

    /**
     * @internal what `inbound()->receive()` (and the package's route) calls
     */
    public function receive(string $key, Request $request): Response
    {
        return $this->container->make(ReceiveWebhookAction::class)->execute($key, $request);
    }

    /**
     * @internal what `inbound()->verify()` calls
     */
    public function verifyInbound(InboundEndpoint $endpoint, Request $request): VerifiedWebhook
    {
        return $this->container->make(VerifyInboundRequestAction::class)->execute($endpoint, $request);
    }

    /**
     * @internal what `inbound()->replay()` calls
     */
    public function replay(InboundDelivery $delivery): InboundDelivery
    {
        return $this->container->make(ReplayInboundDeliveryAction::class)->execute($delivery);
    }

    /**
     * @internal what `outbound()->create()` calls
     *
     * @param  list<string>  $events
     * @param  array<string, string>  $headers
     */
    public function createOutboundEndpoint(
        #[SensitiveParameter] string $url,
        array $events = ['*'],
        ?string $name = null,
        #[SensitiveParameter] array $headers = [],
        string $scheme = 'default',
        ?OutboundEndpointOptions $options = null,
        ?Model $owner = null,
    ): CreatedOutboundEndpoint {
        return $this->container->make(CreateOutboundEndpointAction::class)->execute($url, $events, $name, $headers, $scheme, $options, $owner);
    }

    /**
     * @internal what `outbound()->update()` calls
     */
    public function updateOutboundEndpoint(OutboundEndpoint $endpoint, OutboundEndpointChanges $changes): OutboundEndpoint
    {
        return $this->container->make(UpdateOutboundEndpointAction::class)->execute($endpoint, $changes);
    }

    /**
     * @internal what `outbound()->enable()` / `disable()` call
     */
    public function setOutboundEndpointState(OutboundEndpoint $endpoint, bool $enabled): OutboundEndpoint
    {
        return $this->container->make(SetOutboundEndpointStateAction::class)->execute($endpoint, $enabled);
    }

    /**
     * @internal what `outbound()->delete()` calls
     */
    public function deleteOutboundEndpoint(OutboundEndpoint $endpoint): void
    {
        $this->container->make(DeleteOutboundEndpointAction::class)->execute($endpoint);
    }

    /**
     * @internal what `outbound()->rotateSecret()` calls
     */
    public function rotateOutboundSecret(OutboundEndpoint $endpoint, ?CarbonInterface $grace = null): RotatedSecret
    {
        return $this->container->make(RotateOutboundSecretAction::class)->execute($endpoint, $grace);
    }

    /**
     * @internal what `outbound()->redeliver()` calls
     */
    public function redeliver(OutboundDelivery $delivery): OutboundDelivery
    {
        return $this->container->make(RedeliverWebhookAction::class)->execute($delivery);
    }

    /**
     * @internal what `outbound()->ping()` (and `webhooks:ping`) calls
     */
    public function ping(OutboundEndpoint $endpoint): OutboundDelivery
    {
        return $this->container->make(PingEndpointAction::class)->execute($endpoint);
    }

    /**
     * @internal what `outbound()->redeliverFailed()` (and `webhooks:redeliver --endpoint=`) calls
     */
    public function redeliverFailed(OutboundEndpoint $endpoint, ?CarbonInterface $since = null, bool $includeCancelled = false): RedeliveryResult
    {
        return $this->container->make(RedeliverFailedDeliveriesAction::class)->execute($endpoint, $since, $includeCancelled);
    }

    /**
     * @internal what `outbound()->dispatchDue()` (and `webhooks:retry`) calls
     */
    public function dispatchDue(): int
    {
        return $this->container->make(DispatchDueDeliveriesAction::class)->execute();
    }
}
