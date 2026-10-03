<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medias', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->nullable()->after('group_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('post_id')->nullable()->after('workspace_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('idea_id')->nullable()->after('post_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('rss_feed_item_id')->nullable()->after('idea_id')->index()->constrained()->restrictOnDelete();
            $table->string('mediable_type')->nullable()->change();
            $table->uuid('mediable_id')->nullable()->change();
            $table->index(['collection', 'created_at']);
        });

        $this->backfillWorkspaceIds();
    }

    public function backfillWorkspaceIds(): void
    {
        DB::table('medias')
            ->where('mediable_type', 'workspace')
            ->whereNull('workspace_id')
            ->update(['workspace_id' => DB::raw('mediable_id')]);
    }

    public function down(): void
    {
        Schema::table('medias', function (Blueprint $table): void {
            $table->dropIndex(['collection', 'created_at']);

            foreach (['rss_feed_item_id', 'idea_id', 'post_id', 'workspace_id'] as $column) {
                $table->dropForeign([$column]);
                $table->dropIndex([$column]);
                $table->dropColumn($column);
            }
        });
    }
};
