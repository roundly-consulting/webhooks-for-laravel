<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * An inbound delivery the pipeline accepted: its public id, and whether it was a duplicate of
 * one already stored. Round-trips through sentinel's idempotency store as a plain array.
 *
 * @internal
 */
final readonly class AcceptedDelivery
{
    public function __construct(
        public string $id,
        public bool $duplicate,
    ) {}

    /**
     * What the idempotency store keeps.
     *
     * @return array<string, string|bool>
     */
    public function toStored(): array
    {
        return ['id' => $this->id, 'duplicate' => $this->duplicate];
    }

    /**
     * Back from the idempotency store; a replayed run is a duplicate whatever it stored.
     */
    public static function fromStored(mixed $value, bool $replayed = false): self
    {
        $value = is_array($value) ? $value : [];
        $id = $value['id'] ?? '';

        return new self(is_string($id) ? $id : '', $replayed || ($value['duplicate'] ?? false) === true);
    }
}
