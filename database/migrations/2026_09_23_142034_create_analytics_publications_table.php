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
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('social_account_key');
            $table->foreignUuid('post_platform_id')->nullable()->constrained()->nullOnDelete();
            $table->string('network', 32);
            $table->string('platform_user_id', 191);
            $table->string('platform', 32);
            $table->string('remote_id', 191);
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

            $table->unique('post_platform_id');
            $table->unique(['social_account_key', 'remote_id']);
            $table->index(['workspace_id', 'provider_published_at']);
            $table->index(['workspace_id', 'platform_user_id']);
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
