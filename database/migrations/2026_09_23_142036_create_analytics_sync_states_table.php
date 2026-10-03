<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analytics_sync_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('network', 32)->nullable();
            $table->string('platform_user_id', 191)->nullable();
            $table->string('identity_key', 64);
            $table->string('collector', 32);
            $table->string('status', 32);
            $table->json('checkpoint')->nullable();
            $table->timestamp('target_since')->nullable();
            $table->timestamp('oldest_reached_at')->nullable();
            $table->timestamp('high_watermark_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_category', 64)->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'collector']);
            $table->unique(['identity_key', 'collector']);
            $table->index(['collector', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_sync_states');
    }
};
