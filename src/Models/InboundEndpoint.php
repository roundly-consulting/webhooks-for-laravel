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
use RoundlyConsulting\Webhooks\Database\Factories\InboundEndpointFactory;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\Support\InboundUrls;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * Where one sender posts: an unguessable URL key, the secret(s) it signs with, the scheme, and
 * the handler alias its deliveries go to. The secrets are encrypted and never serialised.
 *
 * @property int|string $id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $name
 * @property string $url_key
 * @property string $signature_scheme
 * @property array<string, mixed>|null $scheme_options
 * @property string|null $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property CarbonImmutable|null $secret_rotated_at
 * @property string|null $handler
 * @property int|null $rate_limit_per_minute
 * @property int|null $max_payload_bytes
 * @property list<string>|null $allowed_content_types
 * @property int|null $tolerance_seconds
 * @property bool $enabled
 * @property CarbonImmutable|null $disabled_at
 * @property DisableReason|null $disabled_reason
 * @property CarbonImmutable|null $last_received_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $owner
 */
class InboundEndpoint extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<InboundEndpointFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    protected $hidden = ['secret', 'previous_secret'];

    public function getTable(): string
    {
        return WebhooksConfig::inboundEndpointsTable();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<InboundDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ModelSeams::inboundDelivery(), 'endpoint_id');
    }

    /**
     * The URL a sender posts to.
     */
    public function url(): string
    {
        return InboundUrls::for($this->url_key);
    }

    /**
     * The secrets that verify right now: the current one, then the previous one until its grace
     * period ends.
     *
     * @return list<string>
     */
    public function activeSecrets(): array
    {
        $secrets = $this->secret === null || $this->secret === '' ? [] : [$this->secret];

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
            'scheme_options' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => UtcDateTime::class,
            'secret_rotated_at' => UtcDateTime::class,
            'rate_limit_per_minute' => 'integer',
            'max_payload_bytes' => 'integer',
            'allowed_content_types' => 'array',
            'tolerance_seconds' => 'integer',
            'enabled' => 'boolean',
            'disabled_at' => UtcDateTime::class,
            'disabled_reason' => DisableReason::class,
            'last_received_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): InboundEndpointFactory
    {
        return InboundEndpointFactory::new();
    }
}
