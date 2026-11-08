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
 * no delivery is written. Endpoint management, `receive()` and `verify()` run for real (a signed
 * request test works under the fake), and rotations / enable / disable are recorded too. Every
 * call through `inbound()`, `outbound()`, `for($owner)` and the `HasWebhooks` trait is seen.
 */
final class WebhooksFake extends WebhooksManager
{
    /** @var list<SentWebhook> */
    private array $sent = [];

    /** @var list<array{type: string, owner: Model|null}> */
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

        $this->broadcasts[] = ['type' => $eventType, 'owner' => $owner];

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
     * @param  OutboundEndpoint|(Closure(SentWebhook): bool)  $endpoint
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertSent(OutboundEndpoint|Closure $endpoint, ?string $eventType = null, ?Closure $payload = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->matching($endpoint, $eventType, $payload),
            'Expected a webhook to be sent'.self::describe($eventType).', but none matched.',
        );
    }

    /**
     * @param  OutboundEndpoint|(Closure(SentWebhook): bool)  $endpoint
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertSentTimes(int $times, OutboundEndpoint|Closure $endpoint, ?string $eventType = null, ?Closure $payload = null): void
    {
        $count = count($this->matching($endpoint, $eventType, $payload));

        PHPUnit::assertSame($times, $count, "Expected {$times} matching webhook(s) to be sent".self::describe($eventType).", but {$count} were.");
    }

    /**
     * @param  OutboundEndpoint|(Closure(SentWebhook): bool)  $endpoint
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     */
    public function assertNotSent(OutboundEndpoint|Closure $endpoint, ?string $eventType = null, ?Closure $payload = null): void
    {
        $count = count($this->matching($endpoint, $eventType, $payload));

        PHPUnit::assertSame(0, $count, 'Expected no matching webhook to be sent'.self::describe($eventType).", but {$count} were.");
    }

    public function assertNothingSent(): void
    {
        $count = count($this->sent);

        PHPUnit::assertSame(0, $count, "Expected no webhook to be sent, but {$count} were.");
    }

    public function assertBroadcast(string $eventType, ?Model $owner = null): void
    {
        $matching = array_filter(
            $this->broadcasts,
            static fn (array $broadcast): bool => $broadcast['type'] === $eventType
                && ($owner === null || ($broadcast['owner'] !== null && $broadcast['owner']->is($owner))),
        );

        PHPUnit::assertNotEmpty($matching, "Expected [{$eventType}] to be broadcast, but it was not.");
    }

    public function assertRedelivered(OutboundDelivery $delivery): void
    {
        PHPUnit::assertNotEmpty(
            array_filter($this->redelivered, static fn (OutboundDelivery $recorded): bool => $recorded->is($delivery)),
            'Expected the delivery to be redelivered, but it was not.',
        );
    }

    public function assertReplayed(InboundDelivery $delivery): void
    {
        PHPUnit::assertNotEmpty(
            array_filter($this->replayed, static fn (InboundDelivery $recorded): bool => $recorded->is($delivery)),
            'Expected the delivery to be replayed, but it was not.',
        );
    }

    public function assertSecretRotated(InboundEndpoint|OutboundEndpoint $endpoint): void
    {
        PHPUnit::assertNotEmpty(
            array_filter($this->rotated, static fn (Model $recorded): bool => $recorded->is($endpoint)),
            "Expected the endpoint's secret to be rotated, but it was not.",
        );
    }

    public function assertEndpointDisabled(InboundEndpoint|OutboundEndpoint $endpoint): void
    {
        PHPUnit::assertNotEmpty(
            array_filter($this->disabled, static fn (Model $recorded): bool => $recorded->is($endpoint)),
            'Expected the endpoint to be disabled, but it was not.',
        );
    }

    /**
     * @param  array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable  $payload
     */
    private function record(OutboundEndpoint $endpoint, string $eventType, array|Arrayable|JsonSerializable $payload, bool $broadcast): OutboundDelivery
    {
        $body = OutboundMessages::body($endpoint, $eventType, $payload);
        $decoded = json_decode($body, true);
        $data = is_array($decoded) && WebhooksConfig::envelope() ? ($decoded['data'] ?? []) : $decoded;

        $this->sent[] = new SentWebhook($endpoint, $eventType, is_array($data) ? $data : [], $body, $broadcast);

        $class = ModelSeams::outboundDelivery();
        $delivery = new $class;
        $delivery->forceFill([
            'public_id' => (string) Str::ulid(),
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
     * @param  OutboundEndpoint|(Closure(SentWebhook): bool)  $endpoint
     * @param  (Closure(array<array-key, mixed>): bool)|null  $payload
     * @return list<SentWebhook>
     */
    private function matching(OutboundEndpoint|Closure $endpoint, ?string $eventType, ?Closure $payload): array
    {
        return array_values(array_filter($this->sent, static fn (SentWebhook $sent): bool => ($endpoint instanceof Closure ? $endpoint($sent) : $sent->endpoint->is($endpoint))
            && ($eventType === null || $sent->eventType === $eventType)
            && ($payload === null || $payload($sent->payload))));
    }

    private static function describe(?string $eventType): string
    {
        return $eventType === null ? '' : " [{$eventType}]";
    }
}
