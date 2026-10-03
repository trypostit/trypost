<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rss_feed_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rss_feed_id')->constrained('rss_feeds')->cascadeOnDelete();
            $table->char('guid_hash', 64);
            $table->string('title');
            $table->string('url', 2048)->nullable();
            $table->text('excerpt')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('image_checked_at')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['rss_feed_id', 'guid_hash']);
            $table->index(['rss_feed_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rss_feed_items');
    }
};
