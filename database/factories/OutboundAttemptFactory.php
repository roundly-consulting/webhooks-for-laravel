<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Support\ModelSeams;

/**
 * @extends Factory<OutboundAttempt>
 */
final class OutboundAttemptFactory extends Factory
{
    protected $model = OutboundAttempt::class;

    public function modelName(): string
    {
        return ModelSeams::outboundAttempt();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => OutboundDelivery::factory(),
            'series' => 1,
            'number' => 1,
            'manual' => false,
            'started_at' => now(),
            'duration_ms' => 12,
            'response_status' => 200,
            'response_truncated' => false,
        ];
    }
}
