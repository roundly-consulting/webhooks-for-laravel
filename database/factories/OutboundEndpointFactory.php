<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Enums\DisableReason;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Support\ModelSeams;
use RoundlyConsulting\Webhooks\Support\Secrets;
use SensitiveParameter;

/**
 * @extends Factory<OutboundEndpoint>
 */
final class OutboundEndpointFactory extends Factory
{
    protected $model = OutboundEndpoint::class;

    public function modelName(): string
    {
        return ModelSeams::outboundEndpoint();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Receiver '.$this->faker->word(),
            'url' => 'https://hooks.example.com/in',
            'url_host' => 'hooks.example.com',
            'signature_scheme' => 'default',
            'secret' => Secrets::generate(),
            'event_types' => ['*'],
            'enabled' => true,
            'consecutive_failures' => 0,
        ];
    }

    public function url(string $url): self
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $this->state(['url' => $url, 'url_host' => is_string($host) ? strtolower(trim($host, '[]')) : '']);
    }

    /**
     * @param  list<string>  $types
     */
    public function subscribedTo(array $types): self
    {
        return $this->state(['event_types' => $types]);
    }

    public function withSecret(#[SensitiveParameter] string $secret): self
    {
        return $this->state(['secret' => $secret]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return $this->state(['headers' => $headers]);
    }

    public function disabled(): self
    {
        return $this->state(['enabled' => false, 'disabled_at' => now(), 'disabled_reason' => DisableReason::Manual]);
    }

    public function ownedBy(Model $owner): self
    {
        return $this->state(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }
}
