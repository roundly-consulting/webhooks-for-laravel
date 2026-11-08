<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How an endpoint's owner renders: its key and its class **basename** — never a class name.
 * Swap it with `webhooks.resources.owner` to add a name or an avatar.
 *
 * @property Model $resource
 */
final class WebhookOwnerResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'type' => class_basename($this->resource)];
    }
}
