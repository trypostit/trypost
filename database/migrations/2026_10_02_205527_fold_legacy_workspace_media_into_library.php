<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * AI images were workspace rows in their own `ai-generated` collection,
     * referenced by posts exactly like library rows; some of them (and of the
     * library) were stored with the class name instead of the `workspace`
     * morph alias, so the owner backfill skipped them. Only those two
     * collections are touched: they join the library (`assets`) so
     * `media:adopt-library` copies them onto the posts that use them and
     * deletes the rest.
     */
    public function up(): void
    {
        $workspaceIds = DB::table('workspaces')->select('id');
        $collections = ['ai-generated', 'assets'];

        DB::table('medias')
            ->where('mediable_type', 'App\\Models\\Workspace')
            ->whereIn('collection', $collections)
            ->whereIn('mediable_id', $workspaceIds)
            ->update(['mediable_type' => 'workspace']);

        DB::table('medias')
            ->where('mediable_type', 'workspace')
            ->whereIn('collection', $collections)
            ->whereNull('workspace_id')
            ->whereIn('mediable_id', $workspaceIds)
            ->update(['workspace_id' => DB::raw('mediable_id')]);

        DB::table('medias')
            ->where('mediable_type', 'workspace')
            ->where('collection', 'ai-generated')
            ->update(['collection' => 'assets']);
    }

    public function down(): void {}
};
