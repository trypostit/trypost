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
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('workspace_limit')->nullable()->after('stripe_yearly_price_id');
        });

        // null means unlimited. Existing rows (the legacy workspace plan) must
        // not read as unlimited between deploy and PlanSeeder.
        DB::table('plans')->whereNull('workspace_limit')->update(['workspace_limit' => 1]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('workspace_limit');
        });
    }
};
