<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\Secrets;
use RoundlyConsulting\Webhooks\Support\UrlKeys;
use SensitiveParameter;

/**
 * @extends Factory<InboundEndpoint>
 */
final class InboundEndpointFactory extends Factory
{
    protected $model = InboundEndpoint::class;

    public function modelName(): string
    {
        return ModelSeams::inboundEndpoint();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Sender '.$this->faker->word(),
            'url_key' => UrlKeys::generate(),
            'signature_scheme' => 'default',
            'secret' => Secrets::generate(),
            'enabled' => true,
        ];
    }

    public function withSecret(#[SensitiveParameter] string $secret): self
    {
        return $this->state(['secret' => $secret]);
    }

    public function disabled(): self
    {
        return $this->state(['enabled' => false, 'disabled_at' => now(), 'disabled_reason' => DisableReason::Manual]);
    }

    public function scheme(string $scheme): self
    {
        return $this->state(['signature_scheme' => $scheme]);
    }

    public function handler(string $alias): self
    {
        return $this->state(['handler' => $alias]);
    }

    public function ownedBy(Model $owner): self
    {
        return $this->state(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }
}
