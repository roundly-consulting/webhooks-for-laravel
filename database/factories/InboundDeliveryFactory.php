<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Enums\SecretSlot;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;

/**
 * @extends Factory<InboundDelivery>
 */
final class InboundDeliveryFactory extends Factory
{
    protected $model = InboundDelivery::class;

    public function modelName(): string
    {
        return ModelSeams::inboundDelivery();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['type' => 'invoice.paid', 'data' => ['id' => $this->faker->randomNumber()]];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'public_id' => (string) Str::ulid(),
            'endpoint_id' => InboundEndpoint::factory(),
            'idempotency_key' => 'msg_'.Str::ulid(),
            'event_type' => 'invoice.paid',
            'status' => InboundStatus::Processed,
            'headers' => ['content-type' => 'application/json'],
            'payload' => $payload,
            'payload_bytes' => strlen($json),
            'payload_digest' => str_repeat('0', 64),
            'signature_slot' => SecretSlot::Current,
            'received_at' => now(),
            'queued_at' => now(),
            'processed_at' => now(),
        ];
    }
}
