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
            $table->uuid('social_account_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->string('network', 32)->nullable();
            $table->string('platform_user_id', 191)->nullable();
            $table->string('collector', 32);
            $table->string('status', 32);
            $table->json('checkpoint')->nullable();
            $table->timestamp('target_since')->nullable();
            $table->timestamp('oldest_reached_at')->nullable();
            $table->timestamp('high_watermark_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_category', 64)->nullable();
            $table->timestamps();

            $table->foreign('workspace_id', 'analytics_sync_states_workspace_fk')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('social_account_id', 'analytics_sync_states_account_fk')
                ->references('id')->on('social_accounts')->nullOnDelete();
            $table->unique(
                ['social_account_id', 'collector'],
                'analytics_sync_states_account_collector_unique',
            );
            $table->unique(
                ['workspace_id', 'network', 'platform_user_id', 'collector'],
                'analytics_sync_states_identity_unique',
            );
            $table->index(['collector', 'status'], 'analytics_sync_states_collector_status_index');
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
