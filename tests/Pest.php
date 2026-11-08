<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Testing\FakeHostResolver;
use RoundlyConsulting\Webhooks\Actions\Outbound\AttemptDeliveryAction;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models\Team;
use RoundlyConsulting\Webhooks\Tests\Install\InstallTestCase;
use RoundlyConsulting\Webhooks\Tests\Support\RouteOffTestCase;
use RoundlyConsulting\Webhooks\Tests\Support\SwappedModelsTestCase;
use RoundlyConsulting\Webhooks\Tests\Support\UlidKeyTestCase;
use RoundlyConsulting\Webhooks\Tests\Support\UuidKeyTestCase;
use RoundlyConsulting\Webhooks\Tests\TestCase;

uses(TestCase::class)->in('Arch', 'Feature', 'Acceptance', 'Network', 'Unit', 'RealEngine', 'Docs');
uses(SwappedModelsTestCase::class)->in('ModelSwap');
uses(InstallTestCase::class)->in('Install');
uses(RouteOffTestCase::class)->in('RouteOff');
uses(UlidKeyTestCase::class)->in('KeyTypes/UlidKeyTest.php');
uses(UuidKeyTestCase::class)->in('KeyTypes/UuidKeyTest.php');

/** Standard Webhooks' published vector secret (24 bytes) and our V-2 / V-3 secrets (32 bytes). */
const V1_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const V2_SECRET = 'whsec_AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
const V3_SECRET = 'whsec_ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8=';

function team(string $name = 'Acme'): Team
{
    return Team::query()->create(['name' => $name]);
}

function inboundEndpoint(array $attributes = []): InboundEndpoint
{
    return InboundEndpoint::factory()->withSecret(V2_SECRET)->create($attributes);
}

/**
 * @param  array<array-key, mixed>  $payload
 * @return TestResponse<Response>
 */
function postSigned(InboundEndpoint $endpoint, array $payload = ['type' => 'invoice.paid', 'data' => ['id' => 42]], ?Closure $tune = null): TestResponse
{
    $request = SignedWebhookRequest::for($endpoint)->payload($payload);

    if ($tune !== null) {
        $tune($request);
    }

    return $request->send();
}

/**
 * The documented default-scheme algorithm, implemented here from the docs text alone — plain
 * PHP, no package code: key = base64-decode(secret after "whsec_"), content = id.ts.body.
 */
function documentedSignature(string $secret, string $id, string $timestamp, string $body): string
{
    $key = base64_decode(substr($secret, strlen('whsec_')), true);

    return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, (string) $key, true));
}

/**
 * Run a delivery's next attempt the way its queued job would (no queue in between).
 */
function attempt(OutboundDelivery $delivery): OutboundDelivery
{
    $delivery->refresh();
    $token = $delivery->dispatch_token ?? throw new LogicException('The delivery has no dispatched job.');

    app(AttemptDeliveryAction::class)
        ->execute($delivery->getKey(), $token, new DeliverWebhook($delivery->getKey(), $token));

    return $delivery->refresh();
}

/**
 * An outbound endpoint at a host the fake DNS resolves publicly.
 *
 * @param  list<string>  $events
 */
function outboundEndpoint(string $url = 'https://hooks.example.com/in', array $events = ['*'], array $attributes = []): OutboundEndpoint
{
    return OutboundEndpoint::factory()->url($url)->subscribedTo($events)->withSecret(V2_SECRET)->create($attributes);
}

/**
 * Public DNS answers for the usual test hosts (and whatever else is given).
 *
 * @param  array<string, string|list<string>>  $extra
 */
function publicDns(array $extra = []): FakeHostResolver
{
    return SafeFetch::fakeDns(['hooks.example.com' => '93.184.216.34', 'other.example.com' => '93.184.216.35', ...$extra]);
}
