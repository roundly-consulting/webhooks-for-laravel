<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\InvalidWebhooksConfigurationException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;

/**
 * A uuid-keyed host: the package's own ids and the owner morph are uuids.
 */
final class UuidTeam extends Model
{
    use HasUniqueStringIds;

    protected $table = 'teams';

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return 'uuid' === 'ulid' ? strtolower((string) Str::ulid()) : (string) Str::uuid7();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return true;
    }
}

it('runs both directions with uuid ids', function (): void {
    Queue::fake();
    publicDns();
    Http::fake(['*' => Http::response('ok')]);

    $team = new UuidTeam(['name' => 'Acme']);
    $team->id = $team->newUniqueId();

    $inbound = Webhooks::for($team)->inbound()->create(secret: V2_SECRET)->endpoint;
    postSigned($inbound)->assertStatus(202);
    $delivery = attempt(Webhooks::for($team)->outbound()->send(Webhooks::for($team)->outbound()->create('https://hooks.example.com/in')->endpoint, 'invoice.paid', ['n' => 1]));

    expect(Str::isUuid($inbound->id))->toBeTrue()
        ->and($inbound->owner_id)->toBe($team->id)
        ->and(Str::isUuid((string) InboundDelivery::query()->sole()->endpoint_id))->toBeTrue()
        ->and($delivery->status)->toBe(OutboundStatus::Succeeded)
        ->and(Str::isUuid($delivery->id))->toBeTrue()
        ->and(Webhooks::for($team)->outbound()->find($delivery->endpoint_id)->getKey())->toBe($delivery->endpoint_id);
});

it('a_mismatched_owner_key_type_fails_fast_naming_the_config_key', function (): void {
    $team = team();   // a bigint key on a uuid install

    expect(fn () => Webhooks::for($team)->inbound()->create())
        ->toThrow(InvalidWebhooksConfigurationException::class, 'webhooks.key_type');
});
