<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_account_daily_snapshots', function (Blueprint $table) {
            $table->index(['workspace_id', 'network', 'platform_user_id', 'date'], 'analytics_account_snapshots_identity_index');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_account_daily_snapshots', function (Blueprint $table) {
            $table->dropIndex('analytics_account_snapshots_identity_index');
        });
    }
};
