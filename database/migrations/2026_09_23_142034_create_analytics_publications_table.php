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
        Schema::create('analytics_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('social_account_id')->nullable();
            $table->uuid('social_account_key');
            $table->uuid('post_platform_id')->nullable();
            $table->string('network', 32);
            $table->string('platform_user_id', 191);
            $table->string('platform', 32);
            $table->string('provider_post_id', 191);
            $table->timestamp('provider_published_at');
            $table->string('origin', 32);
            $table->string('content_type', 32);
            $table->string('availability', 32);
            $table->string('provider_content_type', 64)->nullable();
            $table->text('permalink')->nullable();
            $table->text('excerpt')->nullable();
            $table->json('preview_metadata')->nullable();
            $table->string('account_display_name')->nullable();
            $table->string('account_username')->nullable();
            $table->text('account_avatar_url')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('provider_synced_at')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id', 'analytics_publications_workspace_fk')
                ->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('social_account_id', 'analytics_publications_account_fk')
                ->references('id')->on('social_accounts')->nullOnDelete();
            $table->foreign('post_platform_id', 'analytics_publications_post_platform_fk')
                ->references('id')->on('post_platforms')->nullOnDelete();
            $table->unique('post_platform_id', 'analytics_publications_post_platform_unique');
            $table->unique(
                ['workspace_id', 'social_account_key', 'network', 'provider_post_id'],
                'analytics_publications_identity_unique',
            );
            $table->index(
                ['workspace_id', 'provider_published_at'],
                'analytics_publications_workspace_date_index',
            );
            $table->index(
                ['workspace_id', 'social_account_key', 'provider_published_at'],
                'analytics_publications_account_date_index',
            );
            $table->index(
                ['workspace_id', 'network', 'platform_user_id', 'provider_published_at'],
                'analytics_publications_provider_identity_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_publications');
    }
};
