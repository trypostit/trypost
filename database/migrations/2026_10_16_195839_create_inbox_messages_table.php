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
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inbox_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform_message_id', 512)->nullable();
            $table->char('platform_message_hash', 64)->nullable();
            $table->string('platform_parent_id', 512)->nullable();
            $table->char('platform_parent_hash', 64)->nullable();
            $table->uuid('parent_id')->nullable();
            $table->uuid('root_id')->nullable();
            $table->string('direction', 16);
            $table->string('origin', 16)->default('network');
            $table->foreignUuid('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_status', 16)->nullable();
            $table->string('delivery_error')->nullable();
            $table->string('author_platform_id', 512)->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_username')->nullable();
            $table->string('author_avatar_path')->nullable();
            $table->text('body')->nullable();
            $table->json('media')->nullable();
            $table->json('meta')->nullable();
            $table->string('platform_url', 2048)->nullable();
            $table->timestamp('platform_created_at')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('review_reply_state', 16)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'platform_message_hash'], 'inbox_messages_identity_unique');
            $table->index('sent_by_user_id');
            $table->index('resolved_by_user_id');
            $table->index(['social_account_id', 'platform_parent_hash']);
            $table->index(['inbox_conversation_id', 'platform_created_at']);
            $table->index(['inbox_conversation_id', 'direction', 'answered_at', 'resolved_at'], 'inbox_messages_open_lookup_index');
        });

        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('inbox_messages')->nullOnDelete();
            $table->foreign('root_id')->references('id')->on('inbox_messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
