<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;

/**
 * A ulid-keyed host: the package's own ids and the owner morph are ulids.
 */
final class UlidTeam extends Model
{
    use HasUniqueStringIds;

    protected $table = 'teams';

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return 'ulid' === 'ulid' ? strtolower((string) Str::ulid()) : (string) Str::uuid7();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return true;
    }
}

it('runs both directions with ulid ids', function (): void {
    Queue::fake();
    publicDns();
    Http::fake(['*' => Http::response('ok')]);

    $team = new UlidTeam(['name' => 'Acme']);
    $team->id = $team->newUniqueId();

    $inbound = Webhooks::for($team)->inbound()->create(secret: V2_SECRET)->endpoint;
    postSigned($inbound)->assertStatus(202);
    $delivery = attempt(Webhooks::for($team)->outbound()->send(Webhooks::for($team)->outbound()->create('https://hooks.example.com/in')->endpoint, 'invoice.paid', ['n' => 1]));

    expect(Str::isUlid($inbound->id))->toBeTrue()
        ->and($inbound->owner_id)->toBe($team->id)
        ->and(Str::isUlid((string) InboundDelivery::query()->sole()->endpoint_id))->toBeTrue()
        ->and($delivery->status)->toBe(OutboundStatus::Succeeded)
        ->and(Str::isUlid($delivery->id))->toBeTrue()
        ->and(Webhooks::for($team)->outbound()->find($delivery->endpoint_id)->getKey())->toBe($delivery->endpoint_id);
});

it('a_mismatched_owner_key_type_fails_fast_naming_the_config_key', function (): void {
    $team = team();   // a bigint key on a ulid install

    expect(fn () => Webhooks::for($team)->inbound()->create())
        ->toThrow(InvalidWebhooksConfigurationException::class, 'webhooks.key_type');
});

it('route-binds only a valid key', function (): void {
    $endpoint = inboundEndpoint();

    expect((new InboundEndpoint)->resolveRouteBinding($endpoint->getKey())?->is($endpoint))->toBeTrue()
        ->and(fn () => (new InboundEndpoint)->resolveRouteBinding('not-a-key'))->toThrow(ModelNotFoundException::class);
});
