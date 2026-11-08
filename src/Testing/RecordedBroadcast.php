<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Testing;

use Illuminate\Database\Eloquent\Model;

/**
 * One `broadcast()` call `Webhooks::fake()` recorded — whether or not any endpoint took it.
 */
final readonly class RecordedBroadcast
{
    /**
     * @param  array<array-key, mixed>  $payload  the payload as given (arrays, Arrayable and JsonSerializable decoded)
     */
    public function __construct(
        public string $eventType,
        public ?Model $owner,
        public array $payload,
    ) {}
}
