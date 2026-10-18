<?php

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
        Schema::create('inbox_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained(indexName: 'inbox_conversation_cascade_workspace_id_foreign')->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('analytics_publication_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('post_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('platform_conversation_id', 512);
            $table->char('platform_conversation_hash', 64);
            $table->text('subject_text')->nullable();
            $table->json('subject_media')->nullable();
            $table->string('subject_url', 2048)->nullable();
            $table->timestamp('subject_published_at')->nullable();
            $table->string('participant_name')->nullable();
            $table->string('participant_username')->nullable();
            $table->string('participant_avatar_path')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reply_window_ends_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'kind', 'platform_conversation_hash'], 'inbox_conversations_identity_unique');
            $table->index(['workspace_id', 'kind', 'last_message_at']);
            $table->index(['workspace_id', 'open_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_conversations');
    }
};
