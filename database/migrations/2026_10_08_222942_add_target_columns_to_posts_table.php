<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->uuid('social_account_id')->nullable();
            $table->string('publish_status')->default('pending');
            $table->string('platform')->nullable();
            $table->string('content_type')->nullable();
            $table->string('platform_name')->nullable();
            $table->string('platform_username')->nullable();
            $table->text('platform_avatar')->nullable();
            $table->json('meta')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->text('platform_url')->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->json('thread_reply_ids')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('connection_warning_sent_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('publication_updated_at')->nullable();
            $table->boolean('scheduled_before_media_checks')->default(false);
            $table->uuid('legacy_target_id')->nullable()->unique();

            $table->index(['social_account_id', 'status', 'scheduled_at']);
            $table->index(['social_account_id', 'publish_status', 'published_at']);
            $table->index(['social_account_id', 'platform_post_id']);
            $table->index('platform_post_id');
            $table->index(['publish_status', 'last_reconciled_at']);
            $table->index(['publish_status', 'retry_at']);
            $table->index(['workspace_id', 'publish_status', 'published_at']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->nullOnDelete();
        });

        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->foreignUuid('post_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->dropForeign(['post_id']);
            $table->dropUnique(['post_id']);
            $table->dropColumn('post_id');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropForeign(['social_account_id']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['social_account_id', 'status', 'scheduled_at']);
            $table->dropIndex(['social_account_id', 'publish_status', 'published_at']);
            $table->dropIndex(['social_account_id', 'platform_post_id']);
            $table->dropIndex(['platform_post_id']);
            $table->dropIndex(['publish_status', 'last_reconciled_at']);
            $table->dropIndex(['publish_status', 'retry_at']);
            $table->dropIndex(['workspace_id', 'publish_status', 'published_at']);
            $table->dropUnique(['legacy_target_id']);
            $table->dropColumn([
                'social_account_id',
                'publish_status',
                'platform',
                'content_type',
                'platform_name',
                'platform_username',
                'platform_avatar',
                'meta',
                'platform_post_id',
                'platform_url',
                'error_message',
                'error_context',
                'thread_reply_ids',
                'submitted_at',
                'last_reconciled_at',
                'connection_warning_sent_at',
                'retry_at',
                'publication_updated_at',
                'scheduled_before_media_checks',
                'legacy_target_id',
            ]);
        });
    }
};
