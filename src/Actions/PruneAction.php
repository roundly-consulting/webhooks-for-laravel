<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\DataTransferObjects\PruneOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\PruneResult;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Retention: payloads, bodies and response excerpts older than `payloads.retention_days` are
 * cleared; delivery and attempt rows older than `payloads.delivery_retention_days` are deleted
 * for good (null keeps them), and with them deleted endpoints no delivery points at any more;
 * rotated-out secrets past their grace are cleared. Chunked; a dry run only counts.
 */
final readonly class PruneAction
{
    private const int CHUNK = 500;

    public function execute(?PruneOptions $options = null): PruneResult
    {
        $options ??= new PruneOptions;
        $now = $options->now === null ? Clock::now() : Clock::utc($options->now);
        $dry = $options->dryRun;
        $cutoff = Clock::format($now->subDays(WebhooksConfig::retentionDays()));
        $finished = [OutboundStatus::Succeeded->value, OutboundStatus::Failed->value, OutboundStatus::Cancelled->value];
        $pruned = ['payload_pruned_at' => Clock::format($now)];

        $inboundPayloads = $this->clear(
            ModelSeams::inboundDelivery()::query()->whereNotNull('payload')->where('received_at', '<', $cutoff),
            ['payload' => null, ...$pruned],
            $dry,
        );

        $outboundBodies = $this->clear(
            ModelSeams::outboundDelivery()::query()->whereNotNull('body')->where('created_at', '<', $cutoff)->whereIn('status', $finished),
            ['body' => null, ...$pruned],
            $dry,
        );

        $attemptBodies = $this->clear(
            ModelSeams::outboundAttempt()::query()->whereNotNull('response_body')->where('started_at', '<', $cutoff),
            ['response_body' => null],
            $dry,
        );

        $expired = ['previous_secret' => null, 'previous_secret_expires_at' => null];
        $expiredSecrets = $this->clear(
            ModelSeams::inboundEndpoint()::query()->withTrashed()->whereNotNull('previous_secret')->where('previous_secret_expires_at', '<=', Clock::format($now)),
            $expired,
            $dry,
        ) + $this->clear(
            ModelSeams::outboundEndpoint()::query()->withTrashed()->whereNotNull('previous_secret')->where('previous_secret_expires_at', '<=', Clock::format($now)),
            $expired,
            $dry,
        );

        [$inboundDeliveries, $outboundDeliveries, $attempts, $endpoints] = [0, 0, 0, 0];
        $keepDays = WebhooksConfig::deliveryRetentionDays();

        if ($keepDays !== null) {
            $old = Clock::format($now->subDays($keepDays));
            $oldOutbound = ModelSeams::outboundDelivery()::query()->withTrashed()->where('created_at', '<', $old)->whereIn('status', $finished);

            $attempts = $this->delete(
                ModelSeams::outboundAttempt()::query()->withTrashed()->whereIn('delivery_id', (clone $oldOutbound)->select('id')),
                $dry,
            );
            $outboundDeliveries = $this->delete($oldOutbound, $dry);
            $inboundDeliveries = $this->delete(
                ModelSeams::inboundDelivery()::query()->withTrashed()->where('received_at', '<', $old),
                $dry,
            );

            // Deleted endpoints go once nothing in the log points at them any more.
            $endpoints = $this->delete(
                ModelSeams::inboundEndpoint()::query()->onlyTrashed()->where('deleted_at', '<', $old)
                    ->whereDoesntHave('deliveries', static fn (Builder $query) => $query->withTrashed()),
                $dry,
            ) + $this->delete(
                ModelSeams::outboundEndpoint()::query()->onlyTrashed()->where('deleted_at', '<', $old)
                    ->whereDoesntHave('deliveries', static fn (Builder $query) => $query->withTrashed()),
                $dry,
            );
        }

        return new PruneResult($inboundPayloads, $outboundBodies, $attemptBodies, $inboundDeliveries, $outboundDeliveries, $attempts, $expiredSecrets, $dry, $endpoints);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $values
     */
    private function clear(Builder $query, array $values, bool $dry): int
    {
        if ($dry) {
            return $query->count();
        }

        $count = 0;

        $query->chunkById(self::CHUNK, static function ($rows) use ($query, $values, &$count): void {
            $count += $query->getModel()->newQuery()->withoutGlobalScopes()->whereKey($rows->modelKeys())->update($values);
        });

        return $count;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function delete(Builder $query, bool $dry): int
    {
        if ($dry) {
            return $query->count();
        }

        $count = 0;

        $query->chunkById(self::CHUNK, static function ($rows) use ($query, &$count): void {
            $deleted = $query->getModel()->newQuery()->withoutGlobalScopes()->whereKey($rows->modelKeys())->forceDelete();
            $count += is_int($deleted) ? $deleted : 0;
        });

        return $count;
    }
}
