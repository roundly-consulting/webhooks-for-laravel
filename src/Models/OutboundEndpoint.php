<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Webhooks\Casts\UtcDateTime;
use RoundlyConsulting\Webhooks\Concerns\HasConfigurableKey;
use RoundlyConsulting\Webhooks\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Webhooks\Database\Factories\OutboundEndpointFactory;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\EventTypes;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * One receiver: its URL (encrypted — it may be a capability URL; `url_host` stays readable for
 * listings), signing secret(s), subscriptions and custom headers.
 *
 * @property int|string $id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $name
 * @property string|null $description
 * @property string $url
 * @property string $url_host
 * @property string $signature_scheme
 * @property array<string, mixed>|null $scheme_options
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property CarbonImmutable|null $secret_rotated_at
 * @property list<string> $event_types
 * @property array<string, string>|null $headers
 * @property int|null $rate_limit_per_minute
 * @property bool $enabled
 * @property CarbonImmutable|null $disabled_at
 * @property DisableReason|null $disabled_reason
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_failure_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $owner
 */
class OutboundEndpoint extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<OutboundEndpointFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    protected $hidden = ['secret', 'previous_secret', 'url', 'headers'];

    protected $attributes = [
        'consecutive_failures' => 0,
    ];

    public function getTable(): string
    {
        return WebhooksConfig::outboundEndpointsTable();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<OutboundDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ModelSeams::outboundDelivery(), 'endpoint_id');
    }

    public function subscribesTo(string $eventType): bool
    {
        return EventTypes::matches($this->event_types, $eventType);
    }

    /**
     * The secrets that sign right now: the current one, then the previous one until its grace
     * period ends.
     *
     * @return list<string>
     */
    public function activeSecrets(): array
    {
        $secrets = [$this->secret];

        if ($this->previous_secret !== null && $this->previous_secret !== ''
            && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->isAfter(Clock::now())) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'scheme_options' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => UtcDateTime::class,
            'secret_rotated_at' => UtcDateTime::class,
            'event_types' => 'array',
            'headers' => 'encrypted:array',
            'rate_limit_per_minute' => 'integer',
            'enabled' => 'boolean',
            'disabled_at' => UtcDateTime::class,
            'disabled_reason' => DisableReason::class,
            'consecutive_failures' => 'integer',
            'last_success_at' => UtcDateTime::class,
            'last_failure_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): OutboundEndpointFactory
    {
        return OutboundEndpointFactory::new();
    }
}
