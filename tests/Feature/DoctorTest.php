<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Webhooks\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Webhooks\Enums\FindingLevel;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Support\Doctor;

/**
 * `webhooks:doctor`: each check flags its misconfiguration and stays quiet when it is fine.
 * The baseline is a healthy production-like setup (a database queue, shared stores).
 */
beforeEach(function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('queue.connections.sqs', ['driver' => 'sqs', 'retry_after' => 90]);
    config()->set('cache.stores.shared', ['driver' => 'redis']);
    config()->set('webhooks.inbound.rate_limit.cache_store', 'shared');
    config()->set('http-client-rate-limits.store', 'cache');
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return list<string>
 */
function doctorChecks(): array
{
    return array_map(static fn (DoctorFinding $finding): string => $finding->check, app(Doctor::class)->findings());
}

function doctorFinding(string $check): ?DoctorFinding
{
    foreach (app(Doctor::class)->findings() as $finding) {
        if ($finding->check === $check) {
            return $finding;
        }
    }

    return null;
}

it('is quiet on a healthy setup', function (): void {
    expect(doctorChecks())->toBe([]);
});

it('doctor_flags_schedule_disabled', function (): void {
    config()->set('webhooks.schedule.enabled', false);

    expect(doctorFinding('schedule_disabled')?->level)->toBe(FindingLevel::Warning);
});

it('doctor_is_quiet_when_schedule_disabled_is_fine', function (): void {
    expect(doctorChecks())->not->toContain('schedule_disabled');
});

it('doctor_flags_sweep_stalled', function (): void {
    OutboundDelivery::factory()->status(OutboundStatus::Retrying)->create(['dispatch_token' => null, 'next_attempt_at' => CarbonImmutable::now()->subMinutes(11)]);
    InboundDelivery::factory()->create(['status' => InboundStatus::Received, 'queued_at' => null, 'received_at' => CarbonImmutable::now()->subMinutes(11)]);

    $finding = doctorFinding('sweep_stalled');

    expect($finding?->level)->toBe(FindingLevel::Error)->and($finding?->detail)->toBe('2');
});

it('doctor_is_quiet_when_sweep_stalled_is_fine', function (): void {
    // Due for less than 10 minutes, or with a live job, or finished: the sweep has time.
    OutboundDelivery::factory()->status(OutboundStatus::Retrying)->create(['dispatch_token' => null, 'next_attempt_at' => CarbonImmutable::now()->subMinutes(5)]);
    OutboundDelivery::factory()->status(OutboundStatus::Retrying)->create(['dispatch_token' => 'live', 'next_attempt_at' => CarbonImmutable::now()->subMinutes(30)]);
    OutboundDelivery::factory()->status(OutboundStatus::Failed)->create(['dispatch_token' => null, 'next_attempt_at' => CarbonImmutable::now()->subMinutes(30)]);
    InboundDelivery::factory()->create(['status' => InboundStatus::Received, 'queued_at' => CarbonImmutable::now()->subMinutes(30), 'received_at' => CarbonImmutable::now()->subMinutes(30)]);

    expect(doctorChecks())->not->toContain('sweep_stalled');
});

it('doctor_flags_limiter_store_volatile', function (): void {
    config()->set('webhooks.inbound.rate_limit.cache_store', null);
    config()->set('cache.default', 'array');

    expect(doctorFinding('limiter_store_volatile')?->level)->toBe(FindingLevel::Warning);
});

it('doctor_is_quiet_when_limiter_store_volatile_is_fine', function (): void {
    expect(doctorChecks())->not->toContain('limiter_store_volatile');

    // On a sync queue (local development) one process does everything anyway.
    config()->set('webhooks.inbound.rate_limit.cache_store', 'array');
    config()->set('queue.default', 'sync');
    expect(doctorChecks())->not->toContain('limiter_store_volatile');
});

it('doctor_flags_idempotency_store_volatile', function (): void {
    config()->set('sentinel.idempotency.store', 'cache');
    config()->set('sentinel.idempotency.cache_store', 'array');

    expect(doctorFinding('idempotency_store_volatile')?->level)->toBe(FindingLevel::Error);
});

it('doctor_is_quiet_when_idempotency_store_volatile_is_fine', function (): void {
    config()->set('sentinel.idempotency.store', 'cache');
    config()->set('sentinel.idempotency.cache_store', 'shared');
    expect(doctorChecks())->not->toContain('idempotency_store_volatile');

    config()->set('sentinel.idempotency.store', 'database');
    expect(doctorChecks())->not->toContain('idempotency_store_volatile');
});

it('doctor_flags_handler_retry_after', function (): void {
    config()->set('queue.connections.database.retry_after', 60);   // == inbound.handler.timeout

    expect(doctorFinding('handler_retry_after')?->level)->toBe(FindingLevel::Error)
        ->and(doctorChecks())->not->toContain('retry_after');   // the delivery job's 30 s fit
});

it('doctor_is_quiet_when_handler_retry_after_is_fine', function (): void {
    config()->set('queue.connections.database.retry_after', 61);

    expect(doctorChecks())->not->toContain('handler_retry_after');
});

it('doctor_flags_max_delay_over_driver_limit', function (): void {
    config()->set('queue.default', 'sqs');
    config()->set('webhooks.queue.max_delay', 901);

    expect(doctorFinding('max_delay_over_driver_limit')?->level)->toBe(FindingLevel::Error);
});

it('doctor_is_quiet_when_max_delay_over_driver_limit_is_fine', function (): void {
    config()->set('queue.default', 'sqs');
    expect(doctorChecks())->not->toContain('max_delay_over_driver_limit');

    // Other drivers take longer delays.
    config()->set('queue.default', 'database');
    config()->set('webhooks.queue.max_delay', 3600);
    expect(doctorChecks())->not->toContain('max_delay_over_driver_limit');
});

it('doctor_json_output_is_machine_readable', function (): void {
    config()->set('webhooks.schedule.enabled', false);
    inboundEndpoint(['signature_scheme' => 'gone']);

    expect(Artisan::call('webhooks:doctor', ['--json' => true]))->toBe(1);
    $findings = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($findings)->toHaveCount(2)
        ->and($findings[0])->toBe(['level' => 'warning', 'check' => 'schedule_disabled', 'message' => trans('webhooks::commands.doctor.schedule_disabled'), 'detail' => null])
        ->and($findings[1]['level'])->toBe('error')
        ->and($findings[1]['check'])->toBe('unknown_scheme')
        ->and($findings[1]['detail'])->toBeString();

    config()->set('webhooks.schedule.enabled', true);
    InboundEndpoint::query()->forceDelete();
    $this->artisan('webhooks:doctor', ['--json' => true])->expectsOutput('[]')->assertSuccessful();

    config()->set('webhooks.schedule.enabled', false);
    $this->artisan('webhooks:doctor', ['--json' => true])->assertSuccessful();
});
