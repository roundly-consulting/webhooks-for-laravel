<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\WebhooksManager;
use Throwable;

/**
 * Send a finished outbound delivery again (by its `msg_…` id), or — after an outage — every
 * failed delivery of an endpoint (`--endpoint=`, optionally `--since=` and `--cancelled`).
 */
final class RedeliverCommand extends Command
{
    protected $signature = 'webhooks:redeliver
        {delivery? : The delivery id (msg_…)}
        {--endpoint= : Redeliver every failed delivery of this outbound endpoint id}
        {--since= : With --endpoint: only deliveries created since this date/time}
        {--cancelled : With --endpoint: the deliveries a disable cancelled too}';

    protected $description = 'Redeliver an outbound webhook, or every failed delivery of an endpoint';

    public function handle(WebhooksManager $webhooks): int
    {
        $endpointId = $this->option('endpoint');

        if (is_string($endpointId) && $endpointId !== '') {
            return $this->bulk($webhooks, $endpointId);
        }

        $id = $this->argument('delivery');

        if (! is_string($id) || $id === '') {
            $this->components->error((string) trans('webhooks::commands.redeliver_arguments'));

            return self::FAILURE;
        }

        $id = str_starts_with($id, OutboundDelivery::ID_PREFIX) ? substr($id, strlen(OutboundDelivery::ID_PREFIX)) : $id;
        $delivery = $webhooks->outbound()->deliveries()->where('public_id', $id)->first();

        if ($delivery === null) {
            $this->components->error((string) trans('webhooks::commands.delivery_not_found'));

            return self::FAILURE;
        }

        try {
            $webhooks->outbound()->redeliver($delivery);
        } catch (DeliveryInProgressException|PayloadPrunedException|EndpointNotAcceptingException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info((string) trans('webhooks::commands.redelivered'));

        return self::SUCCESS;
    }

    private function bulk(WebhooksManager $webhooks, string $endpointId): int
    {
        $since = $this->option('since');

        try {
            $since = is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;
        } catch (Throwable) {
            $this->components->error((string) trans('webhooks::commands.invalid_since'));

            return self::FAILURE;
        }

        try {
            $endpoint = $webhooks->outbound()->find($endpointId);
        } catch (EndpointNotFoundException) {
            $this->components->error((string) trans('webhooks::commands.endpoint_not_found'));

            return self::FAILURE;
        }

        try {
            $result = $webhooks->outbound()->redeliverFailed($endpoint, $since, (bool) $this->option('cancelled'));
        } catch (EndpointNotAcceptingException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info((string) trans('webhooks::commands.redelivered_bulk', ['queued' => $result->queued, 'skipped' => $result->skippedPruned]));

        return self::SUCCESS;
    }
}
