<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Renders an endpoint's owner in place through the configured resource.
 *
 * @internal
 */
final class OwnerReference
{
    /**
     * @return array<array-key, mixed>|null
     */
    public static function render(Model $endpoint, Request $request): ?array
    {
        if (! $endpoint->relationLoaded('owner')) {
            return null;
        }

        $owner = $endpoint->getRelation('owner');

        if (! $owner instanceof Model) {
            return null;
        }

        $resource = WebhooksConfig::ownerResource();

        return (new $resource($owner))->resolve($request);
    }
}
