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

        Schema::create(WebhooksConfig::outboundEndpointsTable(), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->morphKey('owner', WebhooksConfig::keyType(), true);
            $table->string('name', 191)->nullable();
            $table->string('description', 1000)->nullable();
            // Encrypted: a receiver URL may carry a token in its path or query.
            $table->text('url');
            $table->string('url_host', 253);
            $table->string('signature_scheme', 64)->default('default');
            $table->json('scheme_options')->nullable();
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->dateTime('previous_secret_expires_at')->nullable();
            $table->dateTime('secret_rotated_at')->nullable();
            $table->json('event_types');
            $table->text('headers')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('disabled_at')->nullable();
            $table->string('disabled_reason', 32)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_failure_at')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index('enabled', 'whout_ep_enabled_idx');
        });
    }
};
