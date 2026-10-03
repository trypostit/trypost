<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rss_feeds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained(indexName: 'rss_feed_cascade_workspace_id_foreign')->cascadeOnDelete();
            $table->foreignUuid('rss_feed_collection_id')->nullable()->constrained('rss_feed_collections')->nullOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('format', 16);
            $table->string('title');
            $table->string('custom_title')->nullable();
            $table->string('site_url', 2048)->nullable();
            $table->string('icon_url', 2048)->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('next_fetch_at')->nullable();
            $table->timestamp('refresh_requested_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'url_hash']);
            $table->index('next_fetch_at');
            $table->index(['workspace_id', 'rss_feed_collection_id']);
            $table->index('rss_feed_collection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rss_feeds');
    }
};
