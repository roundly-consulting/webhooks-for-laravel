<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes an endpoint query to one owner (null: the owner-less endpoints).
 *
 * @internal
 */
final class OwnerEndpoints
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, ?Model $owner): Builder
    {
        return $owner === null
            ? $query->whereNull('owner_type')->whereNull('owner_id')
            : $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }
}
