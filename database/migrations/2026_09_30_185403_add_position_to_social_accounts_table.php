<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('workspace_id');
            $table->index(['workspace_id', 'position']);
        });

        $this->backfillPositions();
    }

    /**
     * Number each workspace's channels in the order the sidebar listed them
     * before positions existed (created_at, then id).
     */
    public function backfillPositions(): void
    {
        $workspaceId = null;
        $position = 0;

        DB::table('social_accounts')
            ->select(['id', 'workspace_id'])
            ->orderBy('workspace_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(500)
            ->each(function (object $account) use (&$workspaceId, &$position): void {
                if ($account->workspace_id !== $workspaceId) {
                    $workspaceId = $account->workspace_id;
                    $position = 0;
                }

                DB::table('social_accounts')->where('id', $account->id)->update(['position' => $position++]);
            });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
