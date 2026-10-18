<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medias', function (Blueprint $table): void {
            $table->foreignUuid('inbox_message_id')->nullable()->after('rss_feed_item_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('inbox_conversation_id')->nullable()->after('inbox_message_id')->index()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medias', function (Blueprint $table): void {
            foreach (['inbox_conversation_id', 'inbox_message_id'] as $column) {
                $table->dropForeign([$column]);
                $table->dropIndex([$column]);
                $table->dropColumn($column);
            }
        });
    }
};
