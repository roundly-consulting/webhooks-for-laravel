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

        Schema::create(WebhooksConfig::inboundEndpointsTable(), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->morphKey('owner', WebhooksConfig::keyType(), true);
            $table->string('name', 191)->nullable();
            $table->string('url_key', 32)->unique('whin_ep_key_unique');
            $table->string('signature_scheme', 64)->default('default');
            $table->json('scheme_options')->nullable();
            $table->text('secret')->nullable();
            $table->text('previous_secret')->nullable();
            $table->dateTime('previous_secret_expires_at')->nullable();
            $table->dateTime('secret_rotated_at')->nullable();
            $table->string('handler', 100)->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedInteger('max_payload_bytes')->nullable();
            $table->json('allowed_content_types')->nullable();
            $table->unsignedInteger('tolerance_seconds')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('disabled_at')->nullable();
            $table->string('disabled_reason', 32)->nullable();
            $table->dateTime('last_received_at')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();
        });
    }
};
