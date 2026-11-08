<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Contracts;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * Put it on one of your own events and dispatching the event broadcasts it as a webhook — to
 * every enabled endpoint of the owner that subscribes to the type (no owner: the app-level
 * endpoints), exactly like `Webhooks::broadcast()`:
 *
 *     final class InvoicePaid implements BroadcastsAsWebhook
 *     {
 *         public function webhookType(): string { return 'invoice.paid'; }
 *         public function webhookPayload(): array { return ['invoice' => $this->invoice->id]; }
 *         public function webhookOwner(): ?Model { return $this->invoice->team; }
 *     }
 */
interface BroadcastsAsWebhook
{
    public function webhookType(): string;

    /**
     * @return array<array-key, mixed>|Arrayable<array-key, mixed>|JsonSerializable
     */
    public function webhookPayload(): array|Arrayable|JsonSerializable;

    public function webhookOwner(): ?Model;
}
