<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use JsonSerializable;
use RoundlyConsulting\Webhooks\Accessors\OwnerWebhooks;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * For a model that owns endpoints (a team, an account). Every call goes through the manager, so
 * `Webhooks::fake()` sees it.
 *
 * @mixin Model
 */
trait HasWebhooks
{
    /**
     * @return MorphMany<InboundEndpoint, $this>
     */
    public function inboundWebhookEndpoints(): MorphMany
    {
        return $this->morphMany(ModelSeams::inboundEndpoint(), 'owner');
    }

    /**
     * @return MorphMany<OutboundEndpoint, $this>
     */
    public function outboundWebhookEndpoints(): MorphMany
    {
        return $this->morphMany(ModelSeams::outboundEndpoint(), 'owner');
    }

    public function webhooks(): OwnerWebhooks
    {
        return app(WebhooksManager::class)->for($this);
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     * @return Collection<int, OutboundDelivery>
     */
    public function broadcastWebhook(string $eventType, array|Arrayable|JsonSerializable $payload): Collection
    {
        return app(WebhooksManager::class)->for($this)->broadcast($eventType, $payload);
    }
}
