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
        Schema::create('inbox_pending_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform_post_id', 512);
            $table->char('platform_post_hash', 64);
            $table->char('platform_message_hash', 64);
            $table->json('payload');
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'platform_message_hash'], 'inbox_pending_messages_identity_unique');
            $table->index(['social_account_id', 'platform_post_hash'], 'inbox_pending_messages_post_lookup_index');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_pending_messages');
    }
};
