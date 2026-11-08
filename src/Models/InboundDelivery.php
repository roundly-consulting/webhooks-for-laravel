<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Webhooks\Casts\StoredPayload;
use RoundlyConsulting\Webhooks\Casts\UtcDateTime;
use RoundlyConsulting\Webhooks\Concerns\HasConfigurableKey;
use RoundlyConsulting\Webhooks\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Webhooks\Database\Factories\InboundDeliveryFactory;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * One delivery a sender posted and the pipeline accepted: the sanitized payload (never the raw
 * body), an allowlist of headers, and how its handler runs went.
 *
 * @property int|string $id
 * @property string $public_id
 * @property int|string $endpoint_id
 * @property string $idempotency_key
 * @property string|null $external_id
 * @property string|null $event_type
 * @property InboundStatus $status
 * @property array<string, string>|null $headers
 * @property array<array-key, mixed>|null $payload
 * @property int $payload_bytes
 * @property string $payload_digest
 * @property CarbonImmutable|null $payload_pruned_at
 * @property SecretSlot|null $signature_slot
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 * @property int $runs
 * @property int $attempts
 * @property int|null $duration_ms
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read InboundEndpoint|null $endpoint
 */
class InboundDelivery extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<InboundDeliveryFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $attributes = [
        'runs' => 1,
        'attempts' => 0,
    ];

    public function getTable(): string
    {
        return WebhooksConfig::inboundDeliveriesTable();
    }

    /**
     * @return BelongsTo<InboundEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(ModelSeams::inboundEndpoint(), 'endpoint_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InboundStatus::class,
            'headers' => 'array',
            'payload' => StoredPayload::class,
            'payload_bytes' => 'integer',
            'payload_pruned_at' => UtcDateTime::class,
            'signature_slot' => SecretSlot::class,
            'received_at' => UtcDateTime::class,
            'queued_at' => UtcDateTime::class,
            'processing_started_at' => UtcDateTime::class,
            'processed_at' => UtcDateTime::class,
            'failed_at' => UtcDateTime::class,
            'runs' => 'integer',
            'attempts' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): InboundDeliveryFactory
    {
        return InboundDeliveryFactory::new();
    }
}
