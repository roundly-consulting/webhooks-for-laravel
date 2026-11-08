<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Handlers\BillingWebhookHandler;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models\HandledWebhook;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes\GitHubScheme;

/**
 * A scheme or handler registered in a host provider's boot() must hold for every job a
 * `queue:work` process runs: the worker resets the container's scoped instances before each
 * job (Octane between requests), and a registration that lived there was gone by the first one.
 */
beforeEach(function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');

    app()->register(new class(app()) extends ServiceProvider
    {
        public function boot(): void
        {
            Webhooks::schemes()->extend('github', GitHubScheme::class);
            Webhooks::handlers()->register('ledger', BillingWebhookHandler::class);
        }
    });
});

afterEach(fn () => CarbonImmutable::setTestNow());

function workThreeJobs(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--max-jobs' => 3, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
}

it('a_handler_registered_in_boot_runs_for_every_job_one_worker_processes', function (): void {
    $endpoint = inboundEndpoint(['handler' => 'ledger']);

    foreach (['msg_one', 'msg_two', 'msg_three'] as $id) {
        postSigned($endpoint, tune: fn ($request) => $request->id($id))->assertStatus(202);
    }

    workThreeJobs();

    expect(HandledWebhook::query()->count())->toBe(3)
        ->and(InboundDelivery::query()->pluck('status')->unique()->all())->toBe([InboundStatus::Processed]);
});

it('an_outbound_custom_scheme_extended_in_boot_signs_the_second_delivery_a_worker_runs', function (): void {
    publicDns();
    $created = Webhooks::outbound()->create('https://hooks.example.com/in', scheme: 'github');
    $seen = [];
    Http::fake(function (ClientRequest $request) use (&$seen) {
        $seen[] = $request;

        return Http::response('ok');
    });

    $first = Webhooks::send($created->endpoint, 'invoice.paid', ['invoice' => 1]);
    $second = Webhooks::send($created->endpoint, 'invoice.paid', ['invoice' => 2]);

    workThreeJobs();

    expect($first->refresh()->status)->toBe(OutboundStatus::Succeeded)
        ->and($second->refresh()->status)->toBe(OutboundStatus::Succeeded)
        ->and($seen)->toHaveCount(2)
        ->and($seen[1]->header('X-Hub-Signature-256')[0])->toBe('sha256='.hash_hmac('sha256', $seen[1]->body(), $created->secret));
});
