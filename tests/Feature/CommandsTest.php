<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Exceptions\SecretRotationException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

it('rotates a secret and prints it once', function (): void {
    $endpoint = inboundEndpoint();

    $this->artisan('webhooks:rotate-secret', ['direction' => 'inbound', 'endpoint' => (string) $endpoint->getKey(), '--grace' => '60'])
        ->expectsOutputToContain('whsec_')
        ->assertSuccessful();

    expect($endpoint->refresh()->previous_secret)->toBe(V2_SECRET);

    $outbound = outboundEndpoint();
    $this->artisan('webhooks:rotate-secret', ['direction' => 'outbound', 'endpoint' => (string) $outbound->getKey()])->assertSuccessful();
    expect($outbound->refresh()->previous_secret)->toBe(V2_SECRET);

    $this->artisan('webhooks:rotate-secret', ['direction' => 'sideways', 'endpoint' => '1'])->assertExitCode(2);
    $this->artisan('webhooks:rotate-secret', ['direction' => 'inbound', 'endpoint' => '1', '--grace' => 'soon'])->assertExitCode(2);
});

it('redelivers and replays by public id', function (): void {
    $delivery = OutboundDelivery::factory()->create(['endpoint_id' => outboundEndpoint()->getKey()]);

    $this->artisan('webhooks:redeliver', ['delivery' => $delivery->webhookId()])->assertSuccessful();
    expect($delivery->refresh()->status)->toBe(OutboundStatus::Pending)->and($delivery->series)->toBe(2);
    Queue::assertPushed(DeliverWebhook::class);

    $this->artisan('webhooks:redeliver', ['delivery' => 'msg_unknown'])->assertFailed();

    $inbound = InboundDelivery::factory()->create();
    $this->artisan('webhooks:replay', ['delivery' => $inbound->public_id])->assertSuccessful();
    Queue::assertPushed(ProcessInboundDelivery::class);
    $this->artisan('webhooks:replay', ['delivery' => 'nope'])->assertFailed();
});

it('fails with the translated error instead of throwing', function (): void {
    $sending = OutboundDelivery::factory()->status(OutboundStatus::Sending)->create(['endpoint_id' => outboundEndpoint()->getKey()]);
    $disabled = outboundEndpoint(attributes: ['enabled' => false]);
    $processing = InboundDelivery::factory()->create(['status' => InboundStatus::Processing, 'processing_started_at' => now()]);
    $pruned = InboundDelivery::factory()->create(['payload' => null, 'payload_pruned_at' => now()]);

    $this->artisan('webhooks:rotate-secret', ['direction' => 'inbound', 'endpoint' => '999'])
        ->expectsOutputToContain(__('webhooks::commands.endpoint_not_found'))->assertFailed();
    $this->artisan('webhooks:rotate-secret', ['direction' => 'inbound', 'endpoint' => (string) inboundEndpoint()->getKey(), '--grace' => '99999999'])
        ->expectsOutputToContain(SecretRotationException::graceTooLong(WebhooksConfig::maxGraceSeconds())->getMessage())->assertFailed();
    $this->artisan('webhooks:replay', ['delivery' => $processing->public_id])
        ->expectsOutputToContain(DeliveryInProgressException::make()->getMessage())->assertFailed();
    $this->artisan('webhooks:replay', ['delivery' => $pruned->public_id])
        ->expectsOutputToContain(PayloadPrunedException::make()->getMessage())->assertFailed();
    $this->artisan('webhooks:redeliver', ['delivery' => $sending->webhookId()])
        ->expectsOutputToContain(DeliveryInProgressException::make()->getMessage())->assertFailed();
    $this->artisan('webhooks:redeliver', ['--endpoint' => (string) $disabled->getKey()])
        ->expectsOutputToContain(EndpointNotAcceptingException::disabled()->getMessage())->assertFailed();
});

it('runs the sweep', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $delivery = Webhooks::send(outboundEndpoint(), 'invoice.paid', []);
    $delivery->forceFill(['dispatch_token' => null])->save();

    $this->artisan('webhooks:retry')->expectsOutputToContain('1')->assertSuccessful();
    expect($delivery->refresh()->dispatch_token)->not->toBeNull();
});

it('prunes with a dry run', function (): void {
    InboundDelivery::factory()->create(['received_at' => CarbonImmutable::now()->subDays(40)]);

    $this->artisan('webhooks:prune', ['--dry-run' => true])->expectsOutputToContain('Would prune 1')->assertSuccessful();
    $this->artisan('webhooks:prune')->expectsOutputToContain('Pruned 1')->assertSuccessful();
});

it('doctor_reports_each_misconfiguration', function (): void {
    config()->set('webhooks.ssrf.enabled', false);
    config()->set('webhooks.outbound.allow_http', true);
    app()->detectEnvironment(fn (): string => 'production');
    inboundEndpoint(['signature_scheme' => 'gone']);
    inboundEndpoint(['handler' => 'vanished']);
    inboundEndpoint(['signature_scheme' => 'rfc9421']);
    outboundEndpoint(attributes: ['signature_scheme' => 'gone']);

    $this->artisan('webhooks:doctor')
        ->expectsOutputToContain('sync')
        ->expectsOutputToContain('SSRF guard is off')
        ->expectsOutputToContain('http is allowed in production')
        ->expectsOutputToContain('unknown signature scheme')
        ->expectsOutputToContain('unknown handler')
        ->expectsOutputToContain('does not cover its delivery id header')
        ->expectsOutputToContain('no sentinel key bound')
        ->assertFailed();
});

it('doctor checks the queue, the store and the tables', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 10);

    $this->artisan('webhooks:doctor')
        ->expectsOutputToContain('in-memory')
        ->expectsOutputToContain('retry_after')
        ->assertFailed();

    config()->set('queue.connections.database.retry_after', 90);
    config()->set('http-client-rate-limits.store', 'cache');
    config()->set('cache.stores.shared', ['driver' => 'redis']);
    config()->set('webhooks.inbound.rate_limit.cache_store', 'shared');
    $this->artisan('webhooks:doctor')->expectsOutputToContain('No problems found')->assertSuccessful();

    config()->set('webhooks.schemes', ['bad' => stdClass::class]);
    $this->artisan('webhooks:doctor')->expectsOutputToContain('configuration is not valid')->assertFailed();
});

it('doctor finds missing tables', function (): void {
    config()->set('queue.default', 'database');
    config()->set('http-client-rate-limits.store', 'cache');
    config()->set('webhooks.tables.inbound_endpoints', 'missing_table');
    config()->set('sentinel.idempotency.store', 'database');
    Schema::rename('sentinel_idempotency_keys', 'sentinel_idempotency_keys_moved');

    try {
        $this->artisan('webhooks:doctor')
            ->expectsOutputToContain('tables are missing')
            ->expectsOutputToContain('idempotency table is missing')
            ->assertFailed();
    } finally {
        Schema::rename('sentinel_idempotency_keys_moved', 'sentinel_idempotency_keys');
    }
});

it('schedules the sweep and the prune', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => $event->command.' '.$event->expression);

    expect($events->first(fn (string $line): bool => str_contains($line, 'webhooks:retry')))->toEndWith('* * * * *')
        ->and($events->first(fn (string $line): bool => str_contains($line, 'webhooks:prune')))->toEndWith('0 0 * * *');
});

it('schedules nothing when the schedule is off, and at the configured frequencies', function (string $retry, string $prune, string $retryCron, string $pruneCron): void {
    config()->set('webhooks.schedule.enabled', false);
    app()->forgetInstance(Schedule::class);
    expect(webhookSchedule())->toBe([]);

    config()->set('webhooks.schedule.enabled', true);
    config()->set('webhooks.schedule.retry', $retry);
    config()->set('webhooks.schedule.prune', $prune);
    app()->forgetInstance(Schedule::class);

    expect(webhookSchedule())->toBe([$retryCron, $pruneCron]);
})->with([
    ['everyTwoMinutes', 'hourly', '*/2 * * * *', '0 * * * *'],
    ['everyFiveMinutes', 'weekly', '*/5 * * * *', '0 0 * * 0'],
]);

/**
 * @return list<string> the cron expressions of the package's scheduled commands
 */
function webhookSchedule(): array
{
    return array_values(array_map(
        static fn ($event): string => (string) $event->expression,
        array_filter(app(Schedule::class)->events(), static fn ($event): bool => str_contains((string) $event->command, 'webhooks:')),
    ));
}
