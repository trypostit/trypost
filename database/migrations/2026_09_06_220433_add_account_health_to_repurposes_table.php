<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The constraint is dropped before the column's nullability changes and
     * recreated after: altering a column a foreign key still points at behaves
     * differently on PostgreSQL and MySQL.
     */
    public function up(): void
    {
        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropForeign(['source_social_account_id']);
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->uuid('source_social_account_id')->nullable()->change();
            $table->string('paused_reason')->nullable()->after('status');
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->foreign('source_social_account_id')
                ->references('id')->on('social_accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Destructive on purpose: a repurpose whose source was deleted has no value
     * to restore into a NOT NULL column, so rolling back drops it.
     */
    public function down(): void
    {
        DB::table('repurposes')->whereNull('source_social_account_id')->delete();

        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropForeign(['source_social_account_id']);
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->uuid('source_social_account_id')->nullable(false)->change();
            $table->dropColumn('paused_reason');
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->foreign('source_social_account_id')
                ->references('id')->on('social_accounts')
                ->cascadeOnDelete();
        });
    }
};
