<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Webhooks\Casts\StoredText;
use RoundlyConsulting\Webhooks\Casts\UtcDateTime;
use RoundlyConsulting\Webhooks\Concerns\HasConfigurableKey;
use RoundlyConsulting\Webhooks\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Webhooks\Database\Factories\OutboundDeliveryFactory;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * One event to one endpoint: the exact body every attempt sends (encrypted at rest), its
 * `webhook-id`, and the retry state.
 *
 * @property int|string $id
 * @property string $public_id
 * @property int|string $endpoint_id
 * @property string $event_type
 * @property string|null $body
 * @property int $body_bytes
 * @property string $body_digest
 * @property CarbonImmutable|null $payload_pruned_at
 * @property OutboundStatus $status
 * @property int $attempts
 * @property int $series
 * @property int $max_attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $claimed_until
 * @property string|null $dispatch_token
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $failed_at
 * @property int|null $last_response_status
 * @property int|null $last_duration_ms
 * @property AttemptError|null $last_error_code
 * @property string|null $last_error_message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read OutboundEndpoint|null $endpoint
 */
class OutboundDelivery extends Model
{
    public const string ID_PREFIX = 'msg_';

    use HasConfigurableKey;

    /** @use HasFactory<OutboundDeliveryFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    protected $hidden = ['body', 'dispatch_token'];

    protected $attributes = [
        'attempts' => 0,
        'series' => 1,
    ];

    public function getTable(): string
    {
        return WebhooksConfig::outboundDeliveriesTable();
    }

    /**
     * The `webhook-id` receivers dedupe on — the same on every attempt and every redelivery.
     */
    public function webhookId(): string
    {
        return self::ID_PREFIX.$this->public_id;
    }

    /**
     * @return BelongsTo<OutboundEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ModelSeams::outboundEndpoint(), 'endpoint_id')->withTrashed();
    }

    /**
     * @return HasMany<OutboundAttempt, $this>
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(ModelSeams::outboundAttempt(), 'delivery_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => StoredText::class,
            'body_bytes' => 'integer',
            'payload_pruned_at' => UtcDateTime::class,
            'status' => OutboundStatus::class,
            'attempts' => 'integer',
            'series' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => UtcDateTime::class,
            'claimed_until' => UtcDateTime::class,
            'dispatched_at' => UtcDateTime::class,
            'last_attempt_at' => UtcDateTime::class,
            'succeeded_at' => UtcDateTime::class,
            'failed_at' => UtcDateTime::class,
            'last_response_status' => 'integer',
            'last_duration_ms' => 'integer',
            'last_error_code' => AttemptError::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): OutboundDeliveryFactory
    {
        return OutboundDeliveryFactory::new();
    }
}
