<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Events;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Contracts\BroadcastsAsWebhook;

/**
 * A host event that broadcasts itself as a webhook.
 */
final readonly class InvoicePaid implements BroadcastsAsWebhook
{
    public function __construct(
        public int $invoice,
        public ?Model $team = null,
    ) {}

    public function webhookType(): string
    {
        return 'invoice.paid';
    }

    /**
     * @return array<string, int>
     */
    public function webhookPayload(): array
    {
        return ['invoice' => $this->invoice];
    }

    public function webhookOwner(): ?Model
    {
        return $this->team;
    }
}
