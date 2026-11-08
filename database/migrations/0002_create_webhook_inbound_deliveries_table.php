<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Webhooks\Support\WebhooksConfig;

return new class extends Migration
{
    public function up(): void
    {
        $primary = WebhooksConfig::primaryKeyType();

        Schema::create(WebhooksConfig::inboundDeliveriesTable(), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->char('public_id', 26)->unique('whin_dl_public_unique');
            $table->ownerKey('endpoint_id', $primary, false, false);
            $table->string('idempotency_key', 255);
            $table->string('external_id', 255)->nullable();
            $table->string('event_type', 191)->nullable();
            $table->string('status', 16);
            $table->json('headers')->nullable();
            $table->longText('payload')->nullable();
            $table->unsignedInteger('payload_bytes');
            $table->char('payload_digest', 64);
            $table->dateTime('payload_pruned_at')->nullable();
            $table->string('signature_slot', 16)->nullable();
            $table->dateTime('received_at');
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->unsignedSmallInteger('runs')->default(1);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('last_error_code', 32)->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            // The backstop for the idempotency store: one delivery per id per endpoint.
            $table->unique(['endpoint_id', 'idempotency_key'], 'whin_dl_key_unique');
            $table->index(['status', 'received_at'], 'whin_dl_status_idx');
            $table->index(['endpoint_id', 'received_at'], 'whin_dl_endpoint_idx');
            // The body guard of timestamp-less, unsigned-id schemes outlives the idempotency store.
            $table->index(['endpoint_id', 'payload_digest'], 'whin_dl_digest_idx');
            $table->foreign('endpoint_id', 'whin_dl_endpoint_fk')->references('id')->on(WebhooksConfig::inboundEndpointsTable())->restrictOnDelete();
        });
    }
};
