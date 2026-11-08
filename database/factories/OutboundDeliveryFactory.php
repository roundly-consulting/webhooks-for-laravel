<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;

/**
 * @extends Factory<OutboundDelivery>
 */
final class OutboundDeliveryFactory extends Factory
{
    protected $model = OutboundDelivery::class;

    public function modelName(): string
    {
        return ModelSeams::outboundDelivery();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = '{"type":"invoice.paid","timestamp":"2026-11-08T12:00:00Z","data":{"invoice":42}}';

        return [
            'public_id' => (string) Str::ulid(),
            'endpoint_id' => OutboundEndpoint::factory(),
            'event_type' => 'invoice.paid',
            'body' => $body,
            'body_bytes' => strlen($body),
            'body_digest' => str_repeat('0', 64),
            'status' => OutboundStatus::Succeeded,
            'attempts' => 1,
            'series' => 1,
            'max_attempts' => 8,
            'succeeded_at' => now(),
        ];
    }

    public function status(OutboundStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
