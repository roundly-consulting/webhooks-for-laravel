<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Accessors;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonSerializable;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * `Webhooks::for($owner)` — one owner's endpoints (a team, an account — any model; no tenancy is
 * assumed). The scope is a boundary: another owner's endpoint is "not found".
 */
final readonly class OwnerWebhooks
{
    public function __construct(
        private WebhooksManager $manager,
        private Model $owner,
    ) {}

    public function inbound(): InboundWebhooks
    {
        return new InboundWebhooks($this->manager, $this->owner);
    }

    public function outbound(): OutboundWebhooks
    {
        return new OutboundWebhooks($this->manager, $this->owner);
    }

    /**
     * Send to every enabled endpoint of this owner that subscribes to the type.
     *
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     * @return Collection<int, OutboundDelivery>
     */
    public function broadcast(string $eventType, array|Arrayable|JsonSerializable $payload): Collection
    {
        return $this->manager->broadcast($eventType, $payload, $this->owner);
    }

    public function owner(): Model
    {
        return $this->owner;
    }
}
