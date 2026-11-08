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

        Schema::create(WebhooksConfig::outboundDeliveriesTable(), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->char('public_id', 26)->unique('whout_dl_public_unique');
            $table->ownerKey('endpoint_id', $primary, false, false);
            $table->string('event_type', 191);
            $table->longText('body')->nullable();
            $table->unsignedInteger('body_bytes');
            $table->char('body_digest', 64);
            $table->dateTime('payload_pruned_at')->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('series')->default(1);
            $table->unsignedSmallInteger('max_attempts');
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('claimed_until')->nullable();
            $table->char('dispatch_token', 26)->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('succeeded_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->string('last_error_code', 32)->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['status', 'next_attempt_at'], 'whout_dl_due_idx');
            $table->index(['endpoint_id', 'created_at'], 'whout_dl_endpoint_idx');
            $table->foreign('endpoint_id', 'whout_dl_endpoint_fk')->references('id')->on(WebhooksConfig::outboundEndpointsTable())->restrictOnDelete();
        });
    }
};
