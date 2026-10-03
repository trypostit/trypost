<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['user_workspace', 'invites'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('is_admin')->default(false);
                $blueprint->boolean('requires_approval')->default(false);
            });

            DB::table($table)->where('role', 'admin')->update(['is_admin' => true]);
            DB::table($table)->where('role', 'viewer')->update(['requires_approval' => true]);
        }

        DB::table('user_workspace')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('workspaces')
                ->join('accounts', 'accounts.id', '=', 'workspaces.account_id')
                ->whereColumn('workspaces.id', 'user_workspace.workspace_id')
                ->whereColumn('accounts.owner_id', 'user_workspace.user_id'))
            ->update(['is_admin' => true, 'requires_approval' => false]);

        Schema::table('user_workspace', function (Blueprint $table): void {
            $table->string('role')->nullable()->change();
        });
    }

    public function down(): void
    {
        foreach (['user_workspace', 'invites'] as $table) {
            DB::table($table)->whereNull('role')->where('is_admin', true)->update(['role' => 'admin']);
            DB::table($table)->whereNull('role')->where('requires_approval', true)->update(['role' => 'viewer']);
            DB::table($table)->whereNull('role')->update(['role' => 'member']);
        }

        Schema::table('user_workspace', function (Blueprint $table): void {
            $table->string('role')->nullable(false)->change();
        });

        foreach (['user_workspace', 'invites'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['is_admin', 'requires_approval']);
            });
        }
    }
};
