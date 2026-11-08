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

        Schema::create(WebhooksConfig::outboundAttemptsTable(), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->ownerKey('delivery_id', $primary, false, false);
            $table->unsignedSmallInteger('series');
            $table->unsignedSmallInteger('number');
            $table->boolean('manual')->default(false);
            $table->dateTime('started_at');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->boolean('response_truncated')->default(false);
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->string('remote_address', 45)->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['delivery_id', 'series', 'number'], 'whout_at_number_unique');
            $table->foreign('delivery_id', 'whout_at_delivery_fk')->references('id')->on(WebhooksConfig::outboundDeliveriesTable())->restrictOnDelete();
        });
    }
};
