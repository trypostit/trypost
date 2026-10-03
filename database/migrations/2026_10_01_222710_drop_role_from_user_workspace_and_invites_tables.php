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
        foreach (['user_workspace', 'invites'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        Schema::table('user_workspace', function (Blueprint $table): void {
            $table->string('role')->nullable();
        });

        Schema::table('invites', function (Blueprint $table): void {
            $table->string('role')->default('member');
        });

        foreach (['user_workspace', 'invites'] as $table) {
            DB::table($table)->where('is_admin', true)->update(['role' => 'admin']);
            DB::table($table)->where('is_admin', false)->where('requires_approval', true)->update(['role' => 'viewer']);
            DB::table($table)->whereNull('role')->update(['role' => 'member']);
        }
    }
};
