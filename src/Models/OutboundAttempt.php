<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Webhooks\Casts\UtcDateTime;
use RoundlyConsulting\Webhooks\Concerns\HasConfigurableKey;
use RoundlyConsulting\Webhooks\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Webhooks\Database\Factories\OutboundAttemptFactory;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

/**
 * One HTTP attempt of an outbound delivery: when, how long, what came back (a capped excerpt, flagged when cut),
 * and why it failed — never a secret, never a vendor's exception message.
 *
 * @property int|string $id
 * @property int|string $delivery_id
 * @property int $series
 * @property int $number
 * @property bool $manual
 * @property CarbonImmutable $started_at
 * @property int|null $duration_ms
 * @property int|null $response_status
 * @property string|null $response_body
 * @property bool $response_truncated
 * @property int|null $retry_after_seconds
 * @property string|null $remote_address
 * @property AttemptError|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read OutboundDelivery|null $delivery
 */
class OutboundAttempt extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<OutboundAttemptFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return WebhooksConfig::outboundAttemptsTable();
    }

    /**
     * @return BelongsTo<OutboundDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ModelSeams::outboundDelivery(), 'delivery_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'series' => 'integer',
            'number' => 'integer',
            'manual' => 'boolean',
            'started_at' => UtcDateTime::class,
            'duration_ms' => 'integer',
            'response_status' => 'integer',
            'response_truncated' => 'boolean',
            'retry_after_seconds' => 'integer',
            'error_code' => AttemptError::class,
            'next_attempt_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): OutboundAttemptFactory
    {
        return OutboundAttemptFactory::new();
    }
}
