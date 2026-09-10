<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // null = unlimited (Workspaces). Default 1 so existing rows (legacy)
            // are not treated as unlimited between migrate and PlanSeeder.
            $table->unsignedInteger('workspace_limit')->nullable()->default(1)->after('stripe_yearly_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('workspace_limit');
        });
    }
};
