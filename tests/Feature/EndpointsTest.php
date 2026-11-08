<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundEndpointOptions;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointOptions;
use RoundlyConsulting\Webhooks\Enums\Direction;
use RoundlyConsulting\Webhooks\Events\EndpointSecretRotated;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Exceptions\SecretRotationException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownHandlerException;
use RoundlyConsulting\Webhooks\Exceptions\UnknownSchemeException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Http\Resources\InboundDeliveryResource;
use RoundlyConsulting\Webhooks\Http\Resources\InboundEndpointResource;
use RoundlyConsulting\Webhooks\Http\Resources\OutboundAttemptResource;
use RoundlyConsulting\Webhooks\Http\Resources\OutboundDeliveryResource;
use RoundlyConsulting\Webhooks\Http\Resources\OutboundEndpointResource;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;

beforeEach(function (): void {
    Queue::fake();
    publicDns();
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('a_generated_secret_is_32_random_bytes_in_whsec_form', function (): void {
    $secrets = collect(range(1, 5))->map(fn () => Webhooks::inbound()->create()->secret);

    expect($secrets->unique())->toHaveCount(5);

    foreach ($secrets as $secret) {
        expect($secret)->toMatch('#^whsec_[A-Za-z0-9+/]{43}=$#')
            ->and(strlen((string) base64_decode(substr($secret, 6), true)))->toBe(32);
    }

    expect(strlen(Webhooks::outbound()->create('https://hooks.example.com/in')->secret))->toBe(50);
});

it('the_secret_never_leaves_the_created_dto', function (): void {
    $created = Webhooks::inbound()->create(handler: 'billing');
    $outbound = Webhooks::outbound()->create('https://hooks.example.com/in/tok3n', headers: ['X-Key' => 'header-secret']);
    $rotated = Webhooks::outbound()->rotateSecret($outbound->endpoint);
    $leaks = [$created->secret, $outbound->secret, $rotated->secret, 'header-secret', 'tok3n'];

    $rendered = json_encode([
        $created, $outbound, $rotated,
        $created->endpoint->toArray(), $outbound->endpoint->toArray(),
        (new InboundEndpointResource($created->endpoint))->resolve(Request::create('/')),
        (new OutboundEndpointResource($outbound->endpoint->refresh()))->resolve(Request::create('/')),
    ]).print_r($created, true).print_r($rotated, true).var_export(json_decode((string) json_encode($outbound)), true);

    foreach ($leaks as $leak) {
        expect($rendered)->not->toContain($leak);
    }
});

it('the_secret_column_holds_ciphertext', function (): void {
    $created = Webhooks::inbound()->create();
    $outbound = Webhooks::outbound()->create('https://hooks.example.com/in/capability-token', headers: ['X-Key' => 'v']);

    $inRow = (array) InboundEndpoint::query()->toBase()->first();
    $outRow = (array) OutboundEndpoint::query()->toBase()->first();

    expect($inRow['secret'])->not->toContain($created->secret)->not->toContain('whsec_')
        ->and($outRow['url'])->not->toContain('capability-token')
        ->and($outRow['url_host'])->toBe('hooks.example.com')
        ->and($outRow['headers'])->not->toContain('X-Key')
        ->and($outRow['secret'])->not->toContain($outbound->secret);
});

it('a_grace_longer_than_the_maximum_is_refused', function (): void {
    $endpoint = inboundEndpoint();

    expect(fn () => Webhooks::inbound()->rotateSecret($endpoint, CarbonImmutable::now()->addDays(8)))->toThrow(SecretRotationException::class, '604800')
        ->and(fn () => Webhooks::inbound()->rotateSecret($endpoint, CarbonImmutable::now()->subMinute()))->toThrow(SecretRotationException::class);
});

it('rotates without overlap when the grace is now', function (): void {
    $endpoint = inboundEndpoint();

    Webhooks::inbound()->rotateSecret($endpoint, CarbonImmutable::now());

    expect($endpoint->refresh()->previous_secret)->toBeNull()->and($endpoint->activeSecrets())->toHaveCount(1);
});

it('rotating_again_during_grace_drops_the_oldest_secret', function (): void {
    Event::fake([EndpointSecretRotated::class]);
    $endpoint = inboundEndpoint();
    $first = Webhooks::inbound()->rotateSecret($endpoint);
    $second = Webhooks::inbound()->rotateSecret($endpoint);

    expect($endpoint->refresh()->secret)->toBe($second->secret)
        ->and($endpoint->previous_secret)->toBe($first->secret)
        ->and($endpoint->activeSecrets())->toBe([$second->secret, $first->secret])
        ->and($second->graceEndsAt->toDateTimeString())->toBe('2026-11-09 12:00:00');
    Event::assertDispatched(EndpointSecretRotated::class, fn (EndpointSecretRotated $event): bool => $event->direction === Direction::Inbound && ! str_contains(serialize($event->graceEndsAt), 'whsec_'));
});

it('imports the sender\'s new secret on rotation', function (): void {
    $endpoint = Webhooks::inbound()->create(secret: 'old-github-secret')->endpoint;

    $rotated = Webhooks::inbound()->rotateSecret($endpoint, secret: 'new-github-secret');

    expect($rotated->secret)->toBe('new-github-secret')->and($endpoint->refresh()->previous_secret)->toBe('old-github-secret');
});

it('concurrent_rotations_never_lose_the_current_secret', function (): void {
    // Rotation reads the current secret under a row lock: a second rotation started from a stale
    // copy of the row still keeps the first rotation's secret as "previous".
    $endpoint = inboundEndpoint();
    $stale = InboundEndpoint::query()->find($endpoint->getKey());

    $first = Webhooks::inbound()->rotateSecret($endpoint);
    $second = Webhooks::inbound()->rotateSecret($stale);

    $endpoint->refresh();
    expect($endpoint->secret)->toBe($second->secret)
        ->and($endpoint->previous_secret)->toBe($first->secret);
});

it('reserved_headers_are_refused', function (string $name): void {
    expect(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', headers: [$name => 'x']))->toThrow(InvalidEndpointException::class);
})->with(['Host', 'Authorization', 'Content-Length', 'Content-Type', 'Transfer-Encoding', 'Connection', 'Cookie', 'Webhook-Id', 'Webhook-Signature', 'Signature-Input', 'Content-Digest', 'Proxy-Authorization']);

it('authorization_is_allowed_only_when_listed', function (): void {
    config()->set('webhooks.outbound.headers.allowed_overrides', ['authorization']);

    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in', headers: ['Authorization' => 'Bearer t'])->endpoint;

    expect($endpoint->headers)->toBe(['authorization' => 'Bearer t'])
        ->and(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', headers: ['Host' => 'evil']))->toThrow(InvalidEndpointException::class);
});

it('header_injection_is_refused', function (array $headers): void {
    expect(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', headers: $headers))->toThrow(InvalidEndpointException::class);
})->with([
    'crlf' => [['X-A' => "a\r\nX-Injected: 1"]],
    'nul' => [['X-A' => "a\0b"]],
    'bad name' => [['X A' => 'b']],
    'numeric name' => [[0 => 'b']],
    'too many' => [array_combine(array_map(fn (int $i): string => 'x-h'.$i, range(1, 21)), array_fill(0, 21, 'v'))],
    'too large' => [['X-Big' => str_repeat('v', 8200)]],
    'non-string' => [['X-A' => 5]],
]);

it('validates endpoint options and changes', function (): void {
    $in = inboundEndpoint();
    $out = outboundEndpoint();

    $refusals = [
        fn () => Webhooks::inbound()->create(name: str_repeat('n', 192)),
        fn () => Webhooks::inbound()->create(options: new InboundEndpointOptions(rateLimitPerMinute: 0)),
        fn () => Webhooks::inbound()->create(options: new InboundEndpointOptions(maxPayloadBytes: 2_000_000)),
        fn () => Webhooks::inbound()->create(options: new InboundEndpointOptions(contentTypes: ['not a type'])),
        fn () => Webhooks::inbound()->create(options: new InboundEndpointOptions(contentTypes: [])),
        fn () => Webhooks::inbound()->create(options: new InboundEndpointOptions(toleranceSeconds: 4000)),
        fn () => Webhooks::inbound()->create(options: new InboundEndpointOptions(schemeOptions: ['Bad Key' => 'x'])),
        fn () => Webhooks::inbound()->update($in, new InboundEndpointChanges(clear: ['secret'])),
        fn () => Webhooks::inbound()->update($in, new InboundEndpointChanges(name: str_repeat('n', 192))),
        fn () => Webhooks::outbound()->create('https://hooks.example.com/in', name: str_repeat('n', 192)),
        fn () => Webhooks::outbound()->create('https://hooks.example.com/in', options: new OutboundEndpointOptions(rateLimitPerMinute: 0)),
        fn () => Webhooks::outbound()->create('https://hooks.example.com/in', options: new OutboundEndpointOptions(description: str_repeat('d', 1001))),
        fn () => Webhooks::outbound()->update($out, new OutboundEndpointChanges(clear: ['url'])),
        fn () => Webhooks::outbound()->update($out, new OutboundEndpointChanges(name: str_repeat('n', 192))),
    ];

    foreach ($refusals as $refusal) {
        expect($refusal)->toThrow(InvalidEndpointException::class);
    }

    expect(fn () => Webhooks::inbound()->update($in, new InboundEndpointChanges(handler: 'nope')))->toThrow(UnknownHandlerException::class)
        ->and(fn () => Webhooks::inbound()->update($in, new InboundEndpointChanges(scheme: 'nope')))->toThrow(UnknownSchemeException::class)
        ->and(fn () => Webhooks::outbound()->update($out, new OutboundEndpointChanges(scheme: 'nope')))->toThrow(UnknownSchemeException::class)
        ->and(fn () => Webhooks::outbound()->create('https://hooks.example.com/in', scheme: 'nope'))->toThrow(UnknownSchemeException::class);

    $updated = Webhooks::inbound()->update($in, new InboundEndpointChanges(handler: 'billing', scheme: 'rfc9421', options: new InboundEndpointOptions(rateLimitPerMinute: 9)));
    expect($updated->handler)->toBe('billing')->and($updated->signature_scheme)->toBe('rfc9421')->and($updated->rate_limit_per_minute)->toBe(9);

    $updated = Webhooks::outbound()->update($out, new OutboundEndpointChanges(headers: [], scheme: 'default', options: new OutboundEndpointOptions(rateLimitPerMinute: 7)));
    expect($updated->headers)->toBeNull()->and($updated->rate_limit_per_minute)->toBe(7);
});

it('a_swapped_owner_resource_is_rendered', function (): void {
    $team = team('Acme');
    $endpoint = Webhooks::for($team)->outbound()->create('https://hooks.example.com/in')->endpoint->load('owner');
    config()->set('webhooks.resources.owner', TeamResource::class);

    expect((new OutboundEndpointResource($endpoint))->resolve(Request::create('/'))['owner'])->toBe(['id' => $team->getKey(), 'name' => 'Acme']);
});

it('a_bad_owner_resource_config_throws', function (mixed $value): void {
    $team = team();
    $endpoint = Webhooks::for($team)->inbound()->create()->endpoint->load('owner');
    config()->set('webhooks.resources.owner', $value);

    expect(fn () => (new InboundEndpointResource($endpoint))->resolve(Request::create('/')))
        ->toThrow(InvalidConfigurationException::class, 'webhooks.resources.owner');
})->with(['not a class' => 'Nope\\Missing', 'not a resource' => stdClass::class, 'the bare base' => JsonResource::class, 'an array' => [[]]]);

it('falls back to the default owner resource on a blank value', function (): void {
    $team = team();
    $endpoint = Webhooks::for($team)->inbound()->create()->endpoint->load('owner');
    config()->set('webhooks.resources.owner', '  ');

    expect((new InboundEndpointResource($endpoint))->resolve(Request::create('/'))['owner'])->toBe(['id' => $team->getKey(), 'type' => 'Team']);
});

it('no_class_name_reaches_the_wire', function (): void {
    $team = team();
    $in = Webhooks::for($team)->inbound()->create(secret: V2_SECRET)->endpoint;
    $out = Webhooks::for($team)->outbound()->create('https://hooks.example.com/in')->endpoint;
    $response = postSigned($in);
    $problem = SignedWebhookRequestTamper($in);
    $delivery = $in->deliveries()->sole();

    $wire = json_encode([
        (new InboundEndpointResource($in->load('owner')))->resolve(Request::create('/')),
        (new OutboundEndpointResource($out->load('owner')))->resolve(Request::create('/')),
        (new InboundDeliveryResource($delivery))->withPayload()->resolve(Request::create('/')),
        (new OutboundDeliveryResource(OutboundDelivery::factory()->create(['endpoint_id' => $out->getKey()])))->resolve(Request::create('/')),
        (new OutboundAttemptResource(OutboundAttempt::factory()->create()))->resolve(Request::create('/')),
        $response->json(), $problem,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($wire)->not->toContain('\\')->not->toContain('RoundlyConsulting')->not->toContain('Fixtures')
        ->and($in->owner_type)->toContain('\\');   // the column does hold one — it is never shown
});

it('renders the delivery log resources', function (): void {
    $in = inboundEndpoint();
    postSigned($in, ['type' => 'invoice.paid', 'password' => 'x']);
    $delivery = $in->deliveries()->sole();

    $plain = (new InboundDeliveryResource($delivery))->resolve(Request::create('/'));
    $full = (new InboundDeliveryResource($delivery))->withPayload()->resolve(Request::create('/'));

    expect($plain)->not->toHaveKey('payload')
        ->and($full['payload'])->toBe(['type' => 'invoice.paid', 'password' => '[redacted]'])
        ->and($full['id'])->toBe($delivery->public_id)
        ->and(OutboundEndpointResource::redact('https://h.example.com:8443/a/b?token=1'))->toBe('https://h.example.com:8443/…')
        ->and(OutboundEndpointResource::redact('not a url'))->toBe('…');
});

/**
 * The problem body a tampered request gets back.
 *
 * @return array<array-key, mixed>
 */
function SignedWebhookRequestTamper(InboundEndpoint $endpoint): array
{
    return (array) SignedWebhookRequest::for($endpoint)->tamper()->send()->json();
}

/**
 * A host's own owner resource.
 */
final class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'name' => $this->resource->name];
    }
}
