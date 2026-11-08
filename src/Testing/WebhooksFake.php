<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Testing;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Webhooks\Actions\Outbound\BroadcastWebhookAction;
use RoundlyConsulting\Webhooks\Actions\Outbound\RedeliverWebhookAction;
use RoundlyConsulting\Webhooks\DataTransferObjects\RotatedSecret;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\DeliveryInProgressException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadPrunedException;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\EventTypes;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\OutboundMessages;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;
use RoundlyConsulting\Webhooks\WebhooksManager;
use SensitiveParameter;

/**
 * Installed by `Webhooks::fake()`. Sends, broadcasts, redeliveries and replays are validated
 * exactly like production (same exceptions) and recorded — nothing is queued, nothing is sent,
 * no delivery is written: a faked `send()` returns an unsaved delivery (`getKey()` is null). Every
 * recorded verb has an `assert…()` and a negative (`assertNot…()` / `assertNothing…()` /
 * `assertNo…()`). Endpoint management, `receive()` and `verify()` run for real (a signed
 * request test works under the fake), and rotations / enable / disable are recorded too. Every
 * call through `inbound()`, `outbound()`, `for($owner)` and the `HasWebhooks` trait is seen.
 */
final class WebhooksFake extends WebhooksManager
{
    /** @var list<SentWebhook> */
    private array $sent = [];

    /** @var list<RecordedBroadcast> */
    private array $broadcasts = [];

    /** @var list<OutboundDelivery> */
    private array $redelivered = [];

    /** @var list<InboundDelivery> */
    private array $replayed = [];

    /** @var list<Model> */
    private array $rotated = [];

    /** @var list<Model> */
    private array $disabled = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     */
    public function send(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload): OutboundDelivery
    {
        return $this->record($endpoint, $eventType, $payload, false);
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     * @return Collection<int, OutboundDelivery>
     */
    public function broadcast(string $eventType, array|Arrayable|JsonSerializable $payload, ?Model $owner = null): Collection
    {
        EventTypes::assertSendable($eventType);

        $this->broadcasts[] = new RecordedBroadcast($eventType, $owner, self::decoded($payload));

        return BroadcastWebhookAction::targets($eventType, $owner)
            ->map(fn (OutboundEndpoint $endpoint): OutboundDelivery => $this->record($endpoint, $eventType, $payload, true));
    }

    public function redeliver(OutboundDelivery $delivery): OutboundDelivery
    {
        RedeliverWebhookAction::assertRedeliverable($delivery);

        $this->redelivered[] = $delivery;

        return $delivery;
    }

    public function replay(InboundDelivery $delivery): InboundDelivery
    {
        if ($delivery->payload_pruned_at !== null || $delivery->payload === null) {
            throw PayloadPrunedException::make();
        }

        if ($delivery->status === InboundStatus::Processing) {
            throw DeliveryInProgressException::make();
        }

        $this->replayed[] = $delivery;

        return $delivery;
    }

    public function rotateInboundSecret(InboundEndpoint $endpoint, ?CarbonInterface $grace = null, #[SensitiveParameter] ?string $secret = null): RotatedSecret
    {
        $rotated = parent::rotateInboundSecret($endpoint, $grace, $secret);
        $this->rotated[] = $endpoint;

        return $rotated;
    }

    public function rotateOutboundSecret(OutboundEndpoint $endpoint, ?CarbonInterface $grace = null): RotatedSecret
    {
        $rotated = parent::rotateOutboundSecret($endpoint, $grace);
        $this->rotated[] = $endpoint;

        return $rotated;
    }

    public function setInboundEndpointState(InboundEndpoint $endpoint, bool $enabled): InboundEndpoint
    {
        if (! $enabled) {
            $this->disabled[] = $endpoint;
        }

        return parent::setInboundEndpointState($endpoint, $enabled);
    }

    public function setOutboundEndpointState(OutboundEndpoint $endpoint, bool $enabled): OutboundEndpoint
    {
        if (! $enabled) {
            $this->disabled[] = $endpoint;
        }

        return parent::setOutboundEndpointState($endpoint, $enabled);
    }

    public function dispatchDue(): int
    {
        return 0;
    }

    /**
     * Every recorded delivery.
     *
     * @return Collection<int, SentWebhook>
     */
    public function sent(): Collection
    {
        return new Collection($this->sent);
    }

    /**
     * Every recorded `broadcast()` call.
     *
     * @return Collection<int, RecordedBroadcast>
     */
    public function broadcasts(): Collection
    {
        return new Collection($this->broadcasts);
    }

    /**
     * Every delivery handed to `redeliver()`.
     *
     * @return Collection<int, OutboundDelivery>
     */
    public function redelivered(): Collection
    {
        return new Collection($this->redelivered);
    }

    /**
     * Every delivery handed to `replay()`.
     *
     * @return Collection<int, InboundDelivery>
     */
    public function replayed(): Collection
    {
        return new Collection($this->replayed);
    }

    /**
     * The first argument is the endpoint, a `Closure(SentWebhook): bool`, or — when you do not hold
     * the endpoint — the event type: `assertSent('invoice.paid', payload: fn (array $p) => …)`.
     *
     * @param  OutboundEndpoint|string|(Closure(SentWebhook): bool)  $endpoint
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertSent(OutboundEndpoint|Closure|string $endpoint, ?string $eventType = null, ?Closure $payload = null): void
    {
        $eventType = self::eventType($endpoint, $eventType);

        PHPUnit::assertNotEmpty(
            $this->matching($endpoint, $eventType, $payload),
            'Expected a webhook to be sent'.self::describe($eventType).', but none matched.',
        );
    }

    /**
     * @param  OutboundEndpoint|string|(Closure(SentWebhook): bool)  $endpoint
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertSentTimes(int $times, OutboundEndpoint|Closure|string $endpoint, ?string $eventType = null, ?Closure $payload = null): void
    {
        $eventType = self::eventType($endpoint, $eventType);
        $count = count($this->matching($endpoint, $eventType, $payload));

        PHPUnit::assertSame($times, $count, "Expected {$times} matching webhook(s) to be sent".self::describe($eventType).", but {$count} were.");
    }

    /**
     * @param  OutboundEndpoint|string|(Closure(SentWebhook): bool)  $endpoint
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertNotSent(OutboundEndpoint|Closure|string $endpoint, ?string $eventType = null, ?Closure $payload = null): void
    {
        $eventType = self::eventType($endpoint, $eventType);
        $count = count($this->matching($endpoint, $eventType, $payload));

        PHPUnit::assertSame(0, $count, 'Expected no matching webhook to be sent'.self::describe($eventType).", but {$count} were.");
    }

    public function assertNothingSent(): void
    {
        $count = count($this->sent);

        PHPUnit::assertSame(0, $count, "Expected no webhook to be sent, but {$count} were.");
    }

    /**
     * A `broadcast()` of the type (for the owner, when given; with a matching payload, when given).
     *
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertBroadcast(string $eventType, ?Model $owner = null, ?Closure $payload = null): void
    {
        PHPUnit::assertNotEmpty($this->broadcastsOf($eventType, $owner, $payload), "Expected [{$eventType}] to be broadcast, but it was not.");
    }

    /**
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertBroadcastTimes(int $times, string $eventType, ?Model $owner = null, ?Closure $payload = null): void
    {
        $count = count($this->broadcastsOf($eventType, $owner, $payload));

        PHPUnit::assertSame($times, $count, "Expected [{$eventType}] to be broadcast {$times} time(s), but it was {$count}.");
    }

    public function assertNotBroadcast(string $eventType, ?Model $owner = null): void
    {
        $count = count($this->broadcastsOf($eventType, $owner, null));

        PHPUnit::assertSame(0, $count, "Expected [{$eventType}] not to be broadcast, but it was {$count} time(s).");
    }

    public function assertNothingBroadcast(): void
    {
        $count = count($this->broadcasts);

        PHPUnit::assertSame(0, $count, "Expected nothing to be broadcast, but {$count} broadcast(s) were.");
    }

    public function assertRedelivered(OutboundDelivery $delivery): void
    {
        PHPUnit::assertNotEmpty(
            array_filter($this->redelivered, static fn (OutboundDelivery $recorded): bool => $recorded->is($delivery)),
            'Expected the delivery to be redelivered, but it was not.',
        );
    }

    public function assertNothingRedelivered(): void
    {
        $count = count($this->redelivered);

        PHPUnit::assertSame(0, $count, "Expected nothing to be redelivered, but {$count} redelivery(ies) were.");
    }

    public function assertReplayed(InboundDelivery $delivery): void
    {
        PHPUnit::assertNotEmpty(
            array_filter($this->replayed, static fn (InboundDelivery $recorded): bool => $recorded->is($delivery)),
            'Expected the delivery to be replayed, but it was not.',
        );
    }

    public function assertNothingReplayed(): void
    {
        $count = count($this->replayed);

        PHPUnit::assertSame(0, $count, "Expected nothing to be replayed, but {$count} replay(s) were.");
    }

    public function assertSecretRotated(InboundEndpoint|OutboundEndpoint $endpoint): void
    {
        PHPUnit::assertNotEmpty(self::recorded($this->rotated, $endpoint), "Expected the endpoint's secret to be rotated, but it was not.");
    }

    public function assertSecretNotRotated(InboundEndpoint|OutboundEndpoint $endpoint): void
    {
        PHPUnit::assertEmpty(self::recorded($this->rotated, $endpoint), "Expected the endpoint's secret not to be rotated, but it was.");
    }

    public function assertNoSecretsRotated(): void
    {
        $count = count($this->rotated);

        PHPUnit::assertSame(0, $count, "Expected no secret to be rotated, but {$count} were.");
    }

    public function assertEndpointDisabled(InboundEndpoint|OutboundEndpoint $endpoint): void
    {
        PHPUnit::assertNotEmpty(self::recorded($this->disabled, $endpoint), 'Expected the endpoint to be disabled, but it was not.');
    }

    public function assertEndpointNotDisabled(InboundEndpoint|OutboundEndpoint $endpoint): void
    {
        PHPUnit::assertEmpty(self::recorded($this->disabled, $endpoint), 'Expected the endpoint not to be disabled, but it was.');
    }

    public function assertNoEndpointsDisabled(): void
    {
        $count = count($this->disabled);

        PHPUnit::assertSame(0, $count, "Expected no endpoint to be disabled, but {$count} were.");
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     */
    private function record(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload, bool $broadcast): OutboundDelivery
    {
        $body = OutboundMessages::body($endpoint, $eventType, $payload);
        $decoded = json_decode($body, true);
        $data = is_array($decoded) && WebhooksConfig::envelope() ? ($decoded['data'] ?? []) : $decoded;

        $publicId = (string) Str::ulid();
        $owner = $endpoint->owner;

        $this->sent[] = new SentWebhook($endpoint, $eventType, is_array($data) ? $data : [], $body, $broadcast, $owner instanceof Model ? $owner : null, OutboundDelivery::ID_PREFIX.$publicId);

        $class = ModelSeams::outboundDelivery();
        $delivery = new $class;
        $delivery->forceFill([
            'public_id' => $publicId,
            'endpoint_id' => $endpoint->getKey(),
            'event_type' => $eventType,
            'body' => $body,
            'body_bytes' => strlen($body),
            'status' => OutboundStatus::Pending,
            'attempts' => 0,
            'series' => 1,
            'max_attempts' => WebhooksConfig::maxAttempts(),
        ]);
        $delivery->setRelation('endpoint', $endpoint);

        return $delivery;
    }

    /**
     * @param  OutboundEndpoint|string|(Closure(SentWebhook): bool)  $endpoint  a string: any endpoint (it is the event type)
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     * @return list<SentWebhook>
     */
    private function matching(OutboundEndpoint|Closure|string $endpoint, ?string $eventType, ?Closure $payload): array
    {
        return array_values(array_filter($this->sent, static fn (SentWebhook $sent): bool => (is_string($endpoint) || ($endpoint instanceof Closure ? $endpoint($sent) : $sent->endpoint->is($endpoint)))
            && ($eventType === null || $sent->eventType === $eventType)
            && ($payload === null || $payload($sent->payload))));
    }

    /**
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     * @return list<RecordedBroadcast>
     */
    private function broadcastsOf(string $eventType, ?Model $owner, ?Closure $payload): array
    {
        return array_values(array_filter($this->broadcasts, static fn (RecordedBroadcast $broadcast): bool => $broadcast->eventType === $eventType
            && ($owner === null || ($broadcast->owner !== null && $broadcast->owner->is($owner)))
            && ($payload === null || $payload($broadcast->payload))));
    }

    /**
     * The event type to match: the first argument when it is one, else the second.
     *
     * @param  OutboundEndpoint|string|(Closure(SentWebhook): bool)  $endpoint
     */
    private static function eventType(OutboundEndpoint|Closure|string $endpoint, ?string $eventType): ?string
    {
        if (is_string($endpoint) && $eventType !== null) {
            throw new InvalidArgumentException('Give the event type once: as the first argument, or after an endpoint.');
        }

        return is_string($endpoint) ? $endpoint : $eventType;
    }

    /**
     * @param  list<Model>  $recorded
     * @return list<Model>
     */
    private static function recorded(array $recorded, Model $endpoint): array
    {
        return array_values(array_filter($recorded, static fn (Model $model): bool => $model->is($endpoint)));
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     * @return array<array-key, mixed>
     */
    private static function decoded(array|Arrayable|JsonSerializable $payload): array
    {
        $decoded = json_decode((string) json_encode($payload instanceof Arrayable ? $payload->toArray() : $payload), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function describe(?string $eventType): string
    {
        return $eventType === null ? '' : " [{$eventType}]";
    }
}
