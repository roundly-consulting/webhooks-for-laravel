<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Webhooks\Testing\WebhooksFake;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Receive and send signed webhooks.
 *
 * @method static \RoundlyConsulting\Webhooks\Models\OutboundDelivery send(\RoundlyConsulting\Webhooks\Models\OutboundEndpoint $endpoint, string $eventType, array<array-key, mixed>|\Illuminate\Contracts\Support\Arrayable<array-key, mixed>|\JsonSerializable $payload)
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Webhooks\Models\OutboundDelivery> broadcast(string $eventType, array<array-key, mixed>|\Illuminate\Contracts\Support\Arrayable<array-key, mixed>|\JsonSerializable $payload, \Illuminate\Database\Eloquent\Model|null $owner = null)
 * @method static \RoundlyConsulting\Webhooks\Accessors\OwnerWebhooks for(\Illuminate\Database\Eloquent\Model $owner)
 * @method static \RoundlyConsulting\Webhooks\Accessors\InboundWebhooks inbound()
 * @method static \RoundlyConsulting\Webhooks\Accessors\OutboundWebhooks outbound()
 * @method static \RoundlyConsulting\Webhooks\Accessors\SchemeRegistry schemes()
 * @method static \RoundlyConsulting\Webhooks\Accessors\HandlerRegistry handlers()
 * @method static \RoundlyConsulting\Webhooks\DataTransferObjects\PruneResult prune(\RoundlyConsulting\Webhooks\DataTransferObjects\PruneOptions|null $options = null)
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Webhooks\Testing\SentWebhook> sent()
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Webhooks\Testing\RecordedBroadcast> broadcasts()
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Webhooks\Models\OutboundDelivery> redelivered()
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Webhooks\Models\InboundDelivery> replayed()
 * @method static void assertSent(\RoundlyConsulting\Webhooks\Models\OutboundEndpoint|\Closure|string $endpoint, string|null $eventType = null, \Closure|null $payload = null)
 * @method static void assertSentTimes(int $times, \RoundlyConsulting\Webhooks\Models\OutboundEndpoint|\Closure|string $endpoint, string|null $eventType = null, \Closure|null $payload = null)
 * @method static void assertNotSent(\RoundlyConsulting\Webhooks\Models\OutboundEndpoint|\Closure|string $endpoint, string|null $eventType = null, \Closure|null $payload = null)
 * @method static void assertNothingSent()
 * @method static void assertBroadcast(string $eventType, \Illuminate\Database\Eloquent\Model|null $owner = null, \Closure|null $payload = null)
 * @method static void assertBroadcastTimes(int $times, string $eventType, \Illuminate\Database\Eloquent\Model|null $owner = null, \Closure|null $payload = null)
 * @method static void assertNotBroadcast(string $eventType, \Illuminate\Database\Eloquent\Model|null $owner = null)
 * @method static void assertNothingBroadcast()
 * @method static void assertRedelivered(\RoundlyConsulting\Webhooks\Models\OutboundDelivery|\RoundlyConsulting\Webhooks\Models\OutboundEndpoint $target)
 * @method static void assertNothingRedelivered()
 * @method static void assertReplayed(\RoundlyConsulting\Webhooks\Models\InboundDelivery $delivery)
 * @method static void assertNothingReplayed()
 * @method static void assertSecretRotated(\RoundlyConsulting\Webhooks\Models\InboundEndpoint|\RoundlyConsulting\Webhooks\Models\OutboundEndpoint $endpoint)
 * @method static void assertSecretNotRotated(\RoundlyConsulting\Webhooks\Models\InboundEndpoint|\RoundlyConsulting\Webhooks\Models\OutboundEndpoint $endpoint)
 * @method static void assertNoSecretsRotated()
 * @method static void assertEndpointDisabled(\RoundlyConsulting\Webhooks\Models\InboundEndpoint|\RoundlyConsulting\Webhooks\Models\OutboundEndpoint $endpoint)
 * @method static void assertEndpointNotDisabled(\RoundlyConsulting\Webhooks\Models\InboundEndpoint|\RoundlyConsulting\Webhooks\Models\OutboundEndpoint $endpoint)
 * @method static void assertNoEndpointsDisabled()
 *
 * @see WebhooksManager
 */
final class Webhooks extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected WebhooksManager is faked too.
     */
    public static function fake(): WebhooksFake
    {
        $fake = app(WebhooksFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return WebhooksManager::class;
    }
}
