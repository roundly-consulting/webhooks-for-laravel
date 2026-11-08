<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('handled_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id');
            $table->string('type')->nullable();
            $table->boolean('replay')->default(false);
            $table->unsignedInteger('run')->default(1);
            $table->timestamps();
        });
    }
};
