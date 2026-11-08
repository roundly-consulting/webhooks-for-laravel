<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Sentinel\Enums\Algorithm;
use RoundlyConsulting\Sentinel\Facades\Sentinel;
use RoundlyConsulting\Sentinel\Keys\KeyStoreManager;
use RoundlyConsulting\Webhooks\Actions\Inbound\CreateInboundEndpointAction;
use RoundlyConsulting\Webhooks\Actions\Inbound\ReceiveWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\RedeliverWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\SendWebhookAction;
use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Events\WebhookReceived;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\ProcessInboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Testing\FakeHostResolver;
use RoundlyConsulting\Webhooks\Testing\SentWebhook;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Schemes\GitHubScheme;
use RoundlyConsulting\Webhooks\WebhooksManager;

/*
 * The technical docs' examples, run. Each block between `// docs: <page> #n` and `// end docs` is a
 * verbatim copy of the n-th php block of docs/packages/webhooks-for-laravel/technical/<page> —
 * keep them identical (an example that throws is a bug). Blocks marked `<!-- shape only -->` in the
 * docs (config fragments, interfaces, a request-bound call) are not run; `<!-- stub: … -->` blocks
 * are the generated recipes and `<!-- receiver: … -->` blocks the receiver snippets, each checked
 * against its source. With the docs tree beside the package (a local checkout), the last test
 * checks every one of these rules.
 */

// docs: README.md #1
final readonly class InvoiceWebhooks
{
    public function __construct(private WebhooksManager $webhooks) {}

    public function paid(OutboundEndpoint $endpoint, int $invoice): OutboundDelivery
    {
        return $this->webhooks->send($endpoint, 'invoice.paid', ['invoice' => $invoice], id: "invoice-{$invoice}-paid");
    }
}
// end docs

// docs: inbound.md #3
final class BillingWebhookHandler implements InboundHandler
{
    public function handle(InboundWebhook $webhook): void
    {
        // $webhook->deliveryId  public id — your own idempotency key for side effects that must never repeat
        // $webhook->type        the scheme's event type, else the payload's "type"
        // $webhook->payload     sanitized, untrusted array — validate it
        // $webhook->headers     the stored allowlist (inbound.store_headers)
        // $webhook->endpoint    the InboundEndpoint
        // $webhook->isReplay, ->run, ->attempt, ->receivedAt
        $invoice = $webhook->get('data.invoice.id');   // dot path into the payload, or null
        $agent = $webhook->header('User-Agent');        // a stored header, case-insensitive
        $team = $webhook->owner();                      // the endpoint's owner, or null
    }
}
// end docs

beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

it('runs the README three-styles examples', function (): void {
    $endpoint = outboundEndpoint(events: ['invoice.*']);
    $key = Webhooks::inbound()->create()->endpoint->url_key;
    $request = HttpRequest::create('/', 'POST');
    $delivery = OutboundDelivery::factory()->create(['endpoint_id' => $endpoint->getKey()]);
    // Under the fake the facade and the manager record; the actions run for real.
    $fake = Webhooks::fake();
    $webhooks = app(WebhooksManager::class);

    // docs: README.md #0
    // Send an event
    Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]);
    $webhooks->send($endpoint, 'invoice.paid', ['invoice' => 42]);                       // WebhooksManager $webhooks, injected
    app(SendWebhookAction::class)->execute($endpoint, 'invoice.paid', ['invoice' => 42]);

    // Create an inbound endpoint
    $created = Webhooks::inbound()->create(handler: 'billing');
    $created = $webhooks->inbound()->create(handler: 'billing');
    $created = app(CreateInboundEndpointAction::class)->execute(null, 'billing');

    // Run the inbound pipeline (what the route does)
    $response = Webhooks::inbound()->receive($key, $request);
    $response = $webhooks->inbound()->receive($key, $request);
    $response = app(ReceiveWebhookAction::class)->execute($key, $request);

    // Redeliver
    Webhooks::outbound()->redeliver($delivery);
    $webhooks->outbound()->redeliver($delivery);
    app(RedeliverWebhookAction::class)->execute($delivery);
    // end docs

    $fake->assertSentTimes(2, 'invoice.paid');
    $fake->assertRedelivered($delivery);
    expect($created->secret)->toStartWith('whsec_')
        ->and($response->getStatusCode())->toBe(415)
        ->and(OutboundDelivery::query()->count())->toBe(2)   // the action's send + the factory's
        ->and($delivery->refresh()->series)->toBe(2);        // the action's redelivery

    $sent = app(InvoiceWebhooks::class)->paid($endpoint, 7);
    $fake->assertSent('invoice.paid', payload: fn (array $payload): bool => $payload === ['invoice' => 7]);
    expect(app(InvoiceWebhooks::class)->paid($endpoint, 7)->webhookId())->toBe($sent->webhookId());
});

it('runs the inbound examples', function (): void {
    Webhooks::schemes()->extend('github', GitHubScheme::class);
    Webhooks::handlers()->register('github', BillingWebhookHandler::class);
    $team = team();
    $theSecretGitHubShowedYou = 'gh-secret';

    // docs: inbound.md #0
    $created = Webhooks::for($team)->inbound()->create(handler: 'billing');   // owned by $team
    $created = Webhooks::inbound()->create(handler: 'billing');               // owner-less
    $created = Webhooks::inbound()->create(
        handler: 'github', scheme: 'github', name: 'GitHub',
        secret: $theSecretGitHubShowedYou,                                   // imported verbatim
        options: new InboundEndpointOptions(rateLimitPerMinute: 120, maxPayloadBytes: 1_048_576,
            contentTypes: ['application/json'], toleranceSeconds: 60),
    );

    $created->endpoint;   // InboundEndpoint
    $created->url;        // route('webhooks.inbound', key) — or inbound.route.url_base + '/' + key
    $created->secret;     // whsec_… (generated) — never readable again
    // end docs

    expect($created->endpoint->signature_scheme)->toBe('github')
        ->and($created->secret)->toBe('gh-secret')
        ->and(Webhooks::for($team)->inbound()->endpoints())->toHaveCount(1);

    // docs: inbound.md #2
    // AppServiceProvider::boot() — lasts for the whole process: every request, every job a queue
    // worker runs, every Octane request
    Webhooks::handlers()->register('billing', BillingWebhookHandler::class);

    // a closure works too
    Webhooks::handlers()->register('audit', function (InboundWebhook $webhook): void {
        logger()->info('webhook', ['id' => $webhook->deliveryId, 'type' => $webhook->type]);
    });
    // end docs

    $logged = [];
    Event::listen(MessageLogged::class, function ($event) use (&$logged): void {
        $logged[] = $event->context['type'] ?? null;
    });
    postSigned(inboundEndpoint(['handler' => 'audit']))->assertStatus(202);
    postSigned(inboundEndpoint(['handler' => 'billing']), tune: fn ($request) => $request->withHeader('User-Agent', 'Sender/1'))->assertStatus(202);
    processQueuedInbound();

    expect($logged)->toBe(['invoice.paid'])
        ->and(InboundDelivery::query()->pluck('status')->unique()->all())->toBe([InboundStatus::Processed]);

    $delivery = InboundDelivery::query()->latest('id')->firstOrFail();
    Queue::fake();
    // docs: inbound.md #4
    Webhooks::inbound()->replay($delivery);   // runs + 1, isReplay = true, same stored payload
    // end docs
    expect($delivery->refresh()->runs)->toBe(2);

    // docs: inbound.md #5
    // WEBHOOKS_INBOUND_ROUTE=off, then in routes/web.php or routes/api.php:
    Route::webhooks('hooks', middleware: ['throttle:120,1']);
    // end docs
    app('router')->getRoutes()->refreshNameLookups();
    expect(collect(Route::getRoutes()->getRoutes())->map->uri()->all())->toContain('hooks/{key}');
});

it('runs the outbound examples', function (): void {
    $team = team();

    // docs: outbound.md #0
    $created = Webhooks::for($team)->outbound()->create(
        'https://hooks.example.com/roundly',
        events: ['invoice.*', 'customer.created'],      // exact, prefix.*, or *
        name: 'ERP',
        headers: ['X-Tenant' => 'acme'],                // custom headers (validated)
        options: new OutboundEndpointOptions(rateLimitPerMinute: 30, description: 'ledger sync'),
    );
    $created->secret;   // whsec_… — hand it to the receiver once
    // end docs

    $endpoint = $created->endpoint;
    $payload = ['invoice' => 42];
    $team->webhooks()->outbound()->create('https://other.example.com/in', events: ['invoice.*']);
    outboundEndpoint(events: ['system.*']);

    // docs: outbound.md #1
    Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]);            // array, Arrayable or JsonSerializable
    Webhooks::send($endpoint, 'invoice.paid', $payload, id: 'invoice-42-paid'); // idempotent: a repeat returns the first delivery
    Webhooks::for($team)->broadcast('invoice.paid', $payload);                // every enabled, subscribed endpoint of $team
    Webhooks::broadcast('system.maintenance', $payload);                      // owner-less endpoints only
    $team->broadcastWebhook('invoice.paid', $payload);                        // the HasWebhooks trait
    Webhooks::outbound()->ping($endpoint);                                    // a webhook.ping test event
    // end docs

    $webhooks = app(WebhooksManager::class);
    // docs: outbound.md #2
    $webhooks->send($endpoint, 'invoice.paid', ['invoice' => 42]);                       // WebhooksManager $webhooks
    app(SendWebhookAction::class)->execute($endpoint, 'invoice.paid', ['invoice' => 42]);
    // end docs

    expect(OutboundDelivery::query()->where('event_type', 'webhook.ping')->count())->toBe(1)
        ->and(OutboundDelivery::query()->where('event_type', 'system.maintenance')->count())->toBe(1);

    $delivery = OutboundDelivery::factory()->status(OutboundStatus::Failed)->create(['endpoint_id' => $endpoint->getKey()]);
    // docs: outbound.md #4
    Webhooks::outbound()->redeliver($delivery);   // or: php artisan webhooks:redeliver msg_…
    // end docs

    OutboundDelivery::factory()->status(OutboundStatus::Failed)->create(['endpoint_id' => $endpoint->getKey()]);
    // docs: outbound.md #5
    $result = Webhooks::for($team)->outbound()->redeliverFailed($endpoint, since: now()->subDay(), includeCancelled: true);

    $result->queued;          // redelivered
    $result->skippedPruned;   // their body was already pruned — cannot be sent again
    // end docs

    expect($result->queued)->toBe(1)->and($result->skippedPruned)->toBe(0);
});

it('runs the events examples', function (): void {
    // docs: events.md #0
    Event::listen(function (WebhookReceived $event): void {
        if (! $event->webhook->isFirstAttempt()) {
            return;   // a retry of a run whose handler threw
        }

        Cache::increment('webhooks.received.'.$event->webhook->type);
    });
    // end docs

    postSigned(inboundEndpoint())->assertStatus(202);
    processQueuedInbound();

    expect(Cache::get('webhooks.received.invoice.paid'))->toBe(1);
});

it('runs the delivery-log examples', function (): void {
    $endpoint = inboundEndpoint();
    $delivery = OutboundDelivery::factory()->create();

    // docs: delivery-log.md #0
    Webhooks::inbound()->deliveries($endpoint)->where('status', 'failed')->latest('received_at')->paginate();
    Webhooks::outbound()->deliveries()->where('status', 'retrying')->get();
    Webhooks::outbound()->attempts($delivery);      // every attempt of every series, in order
    // end docs

    expect(Webhooks::outbound()->attempts($delivery))->toHaveCount(0);
});

it('runs the secrets examples', function (): void {
    $inboundEndpoint = inboundEndpoint();
    $outboundEndpoint = outboundEndpoint();
    $sendersNewSecret = 'whsec_'.base64_encode(str_repeat('n', 32));

    // docs: secrets.md #0
    $rotated = Webhooks::inbound()->rotateSecret($inboundEndpoint);                          // grace: secrets.grace_seconds (1 day)
    $rotated = Webhooks::outbound()->rotateSecret($outboundEndpoint, grace: now()->addHours(6));
    $rotated = Webhooks::inbound()->rotateSecret($inboundEndpoint, grace: now());            // no overlap
    $rotated = Webhooks::inbound()->rotateSecret($inboundEndpoint, secret: $sendersNewSecret); // import (inbound)

    $rotated->secret;       // the new secret, shown once
    $rotated->graceEndsAt;  // when the old one stops working
    // end docs

    expect($inboundEndpoint->refresh()->secret)->toBe($sendersNewSecret)
        ->and($rotated->graceEndsAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('runs the signature-schemes registration example', function (): void {
    Webhooks::handlers()->register('github', BillingWebhookHandler::class);
    $secretYouEnteredInGitHub = 'gh-secret';

    // docs: signature-schemes.md #2
    Webhooks::schemes()->extend('github', GitHubScheme::class);
    Webhooks::inbound()->create(scheme: 'github', secret: $secretYouEnteredInGitHub, handler: 'github');
    // end docs

    expect(Webhooks::schemes()->has('github'))->toBeTrue();
});

it('runs the testing examples', function (): void {
    $team = team();   // no endpoints: its broadcast records a broadcast, no send
    $endpoint = outboundEndpoint(events: ['invoice.*']);
    $otherEndpoint = outboundEndpoint('https://other.example.com/in');
    $invoice = 42;
    $sendInvoiceWebhook = function (int $invoice) use ($team, $endpoint): void {
        Webhooks::send($endpoint, 'invoice.paid', ['invoice' => $invoice]);
        Webhooks::for($team)->broadcast('invoice.paid', ['invoice' => $invoice]);
    };

    // docs: testing.md #0
    $fake = Webhooks::fake();

    $sendInvoiceWebhook($invoice);   // your code calls Webhooks::send() / broadcast() / …

    $fake->assertSent($endpoint, 'invoice.paid', fn (array $payload): bool => $payload['invoice'] === 42);
    $fake->assertSent('invoice.paid', payload: fn (array $payload): bool => $payload['invoice'] === 42);   // without the endpoint
    $fake->assertSent(fn (SentWebhook $sent): bool => $sent->endpoint->is($endpoint) && ! $sent->broadcast);
    $fake->assertSentTimes(1, 'invoice.paid');
    $fake->assertNotSent($otherEndpoint);
    $fake->assertBroadcast('invoice.paid', $team, fn (array $payload): bool => $payload['invoice'] === 42);
    $fake->assertBroadcastTimes(1, 'invoice.paid');
    $fake->assertNotBroadcast('invoice.void');
    // end docs

    expect($fake->broadcasts())->toHaveCount(1);
});

it('runs the signed-request examples', function (): void {
    $endpoint = inboundEndpoint();
    $rotatedEndpoint = inboundEndpoint();
    Webhooks::inbound()->rotateSecret($rotatedEndpoint);
    $rfc9421Endpoint = rfc9421DocsEndpoint();
    $instant = CarbonImmutable::now();

    // docs: testing.md #1
    SignedWebhookRequest::for($endpoint)
        ->payload(['type' => 'invoice.paid', 'data' => ['id' => 42]])   // or ->body('exact bytes')
        ->send()                                                          // through the HTTP kernel: TestResponse
        ->assertAccepted();

    SignedWebhookRequest::for($endpoint)->tamper()->send()->assertUnauthorized();
    SignedWebhookRequest::for($endpoint)->staleBy(600)->send()->assertUnauthorized();
    SignedWebhookRequest::for($endpoint)->unsigned()->send()->assertUnauthorized();                 // missing_signature
    SignedWebhookRequest::for($endpoint)->withoutHeader('webhook-timestamp')->send()->assertUnauthorized();
    SignedWebhookRequest::for($endpoint)->idempotencyKey('order-7')->send();
    SignedWebhookRequest::for($rotatedEndpoint)->signedWithPrevious()->send();   // rotation grace
    SignedWebhookRequest::for($endpoint)->id('msg_1')->at($instant)->secret('whsec_…')->withHeader('X-A', 'b');
    SignedWebhookRequest::for($rfc9421Endpoint)->keyId('partner')->send();     // signed by sentinel
    $data = SignedWebhookRequest::for($endpoint)->toRequest();                    // SignedRequestData: uri, headers, body
    $this->call('POST', $data->uri, [], [], [], $data->server(), $data->body);    // your own call
    // end docs

    expect($data->headers)->toHaveKey('webhook-signature')
        ->and(InboundDelivery::query()->count())->toBe(5);
});

it('runs the DNS testing examples', function (): void {
    // docs: testing.md #2
    $dns = FakeHostResolver::map(['hooks.example.com' => '93.184.216.34'])->install();   // = SafeFetch::fakeDns()
    Http::fake(['*' => Http::response('ok')]);
    // end docs

    $delivery = attempt(Webhooks::send(Webhooks::outbound()->create('https://hooks.example.com/in')->endpoint, 'invoice.paid', []));

    $dns->assertLookedUp('hooks.example.com', 2);   // on save and on the attempt
    expect($delivery->status)->toBe(OutboundStatus::Succeeded);
});

it('runs the pin-inspecting fake example', function (): void {
    publicDns();

    // docs: testing.md #3
    Http::fake(fn (Request $request, array $options) => Http::response($options['curl'][CURLOPT_RESOLVE][0]));
    // end docs

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', []));

    expect(OutboundAttempt::query()->sole()->response_body)->toBe('hooks.example.com:443:93.184.216.34');
});

it('runs the receiver guide example', function (): void {
    $secretTheSenderShowedYou = 'whsec_'.base64_encode(str_repeat('s', 32));

    // docs: receiving-our-webhooks.md #2
    $created = Webhooks::inbound()->create(handler: 'billing', secret: $secretTheSenderShowedYou);
    $created->url;   // the address to paste into the sender's settings
    // end docs

    expect($created->endpoint->secret)->toBe($secretTheSenderShowedYou);
});

/**
 * Run every queued inbound delivery's job, the way a worker would.
 */
function processQueuedInbound(): void
{
    foreach (InboundDelivery::query()->where('status', 'queued')->get() as $delivery) {
        app()->call([new ProcessInboundDelivery($delivery->getKey()), 'handle']);
    }
}

/**
 * An RFC 9421 endpoint whose partner key is bound to it (as in tests/Feature/Schemes/Rfc9421Test).
 */
function rfc9421DocsEndpoint(): InboundEndpoint
{
    $key = 'base64:'.base64_encode('partner-shared-secret-32-bytes-long-for-hmac');
    config()->set('sentinel.keys.rings.partner', ['driver' => 'config', 'key_id' => 'partner', 'algorithm' => 'hmac-sha256', 'key' => $key, 'algorithms' => ['hmac-sha256']]);
    config()->set('sentinel.signatures.outbound.ring', 'partner');
    config()->set('sentinel.keys.rings.http.driver', 'database');
    app(KeyStoreManager::class)->flush();

    $endpoint = Webhooks::inbound()->create(scheme: 'rfc9421', options: new InboundEndpointOptions(schemeOptions: ['id_header' => 'webhook-id']))->endpoint;
    Sentinel::keys()->ring('http')->import('partner', Algorithm::HmacSha256, $key, owner: $endpoint);

    return $endpoint;
}

it('keeps the docs and these copies identical', function (): void {
    $docs = realpath(__DIR__.'/../../../docs/packages/webhooks-for-laravel/technical');

    if ($docs === false) {
        $this->markTestSkipped('the docs tree is not beside the package (CI): checked on a local checkout');
    }

    $source = (string) file_get_contents(__FILE__);
    preg_match_all('#^( *)// docs: (\S+) \#(\d+)\n(.*?)^ *// end docs$#ms', $source, $copies, PREG_SET_ORDER);
    $copied = [];

    foreach ($copies as [, $indent, $page, $number, $code]) {
        $copied[$page.'#'.$number] = preg_replace('/^'.$indent.'/m', '', rtrim($code, "\n"));
    }

    $checked = 0;

    foreach (glob($docs.'/*.md') ?: [] as $file) {
        $page = basename($file);
        preg_match_all('/(?:^(<!-- [^>]+ -->)\n)?^```php\n(.*?)^```$/ms', (string) file_get_contents($file), $blocks, PREG_SET_ORDER);

        foreach ($blocks as $number => [, $marker, $code]) {
            $code = rtrim($code, "\n");
            $where = "{$page} php block #{$number}";
            $checked++;

            match (true) {
                $marker === '<!-- shape only -->' => null,
                str_starts_with($marker, '<!-- stub: ') => expect($code)->toBe(renderedStub(substr($marker, 11, -4)), $where.' is not the generated recipe'),
                str_starts_with($marker, '<!-- receiver: ') => expect($code)->toBe(rtrim((string) file_get_contents(__DIR__.'/../Fixtures/receivers/'.substr($marker, 15, -4)), "\n"), $where.' is not the receiver snippet'),
                default => expect($copied)->toHaveKey($page.'#'.$number, message: $where.' is neither run here nor marked shape only')
                    ->and($copied[$page.'#'.$number] ?? null)->toBe($code, $where.' differs from its copy here'),
            };
        }
    }

    // The receiver guide embeds every snippet in other languages too.
    $guide = (string) file_get_contents($docs.'/receiving-our-webhooks.md');

    foreach (['verify.js' => 'js', 'verify.py' => 'python', 'verify.go' => 'go', 'verify.rb' => 'ruby'] as $file => $fence) {
        expect($guide)->toContain("<!-- receiver: {$file} -->\n```{$fence}\n".(string) file_get_contents(__DIR__.'/../Fixtures/receivers/'.$file).'```');
    }

    expect($checked)->toBeGreaterThan(25)->and(count($copied))->toBeGreaterThan(15);
});

function renderedStub(string $recipe): string
{
    $class = ['github' => 'GitHubScheme', 'stripe' => 'StripeScheme'][$recipe];

    return rtrim(str_replace(['{{ namespace }}', '{{ class }}'], ['App\\Webhooks\\Schemes', $class], (string) file_get_contents(__DIR__.'/../../stubs/scheme.'.$recipe.'.stub')), "\n");
}
