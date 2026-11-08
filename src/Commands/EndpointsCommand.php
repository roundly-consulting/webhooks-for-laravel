<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\Keys;
use RoundlyConsulting\Webhooks\Support\ModelSeams;

/**
 * List endpoints — never a secret, a header or a full outbound URL (it may carry a token): the
 * host only. `--owner=Type:id` narrows to one owner (the owner's morph class and key).
 */
final class EndpointsCommand extends Command
{
    protected $signature = 'webhooks:endpoints {--owner= : Only this owner\'s endpoints, as "morph-class:id"}';

    protected $description = 'List the webhook endpoints';

    public function handle(): int
    {
        $owner = $this->option('owner');
        [$type, $id] = [null, null];

        if (is_string($owner) && $owner !== '') {
            $at = strrpos($owner, ':');

            if ($at === false || $at === 0 || $at === strlen($owner) - 1) {
                $this->components->error((string) trans('webhooks::commands.invalid_owner'));

                return self::INVALID;
            }

            $type = substr($owner, 0, $at);
            $id = substr($owner, $at + 1);
        }

        $rows = [];

        foreach (self::scoped(ModelSeams::inboundEndpoint()::query(), $type, $id)->orderBy('id')->get() as $endpoint) {
            /** @var InboundEndpoint $endpoint */
            $rows[] = ['inbound', (string) Keys::of($endpoint), $endpoint->name ?? '', self::owner($endpoint->owner_type, $endpoint->owner_id), '/'.$endpoint->url_key, $endpoint->signature_scheme, $endpoint->handler ?? '—', $endpoint->enabled ? 'yes' : 'no'];
        }

        foreach (self::scoped(ModelSeams::outboundEndpoint()::query(), $type, $id)->orderBy('id')->get() as $endpoint) {
            /** @var OutboundEndpoint $endpoint */
            $rows[] = ['outbound', (string) Keys::of($endpoint), $endpoint->name ?? '', self::owner($endpoint->owner_type, $endpoint->owner_id), $endpoint->url_host, $endpoint->signature_scheme, implode(', ', $endpoint->event_types), $endpoint->enabled ? 'yes' : 'no'];
        }

        if ($rows === []) {
            $this->components->info((string) trans('webhooks::commands.no_endpoints'));

            return self::SUCCESS;
        }

        $this->table(['direction', 'id', 'name', 'owner', 'where', 'scheme', 'handler / events', 'enabled'], $rows);

        return self::SUCCESS;
    }

    /**
     * @template TModel of InboundEndpoint|OutboundEndpoint
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function scoped(Builder $query, ?string $type, ?string $id): Builder
    {
        return $type === null ? $query : $query->where('owner_type', $type)->where('owner_id', $id);
    }

    private static function owner(?string $type, int|string|null $id): string
    {
        return $type === null || $id === null ? '—' : class_basename($type).':'.$id;
    }
}
