<?php

declare(strict_types=1);

use App\Enums\Repurpose\SourceFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repurposes', function (Blueprint $table) {
            $table->string('source_format')->default(SourceFormat::Reel->value)->after('source_social_account_id');
        });

        // A repurpose watches one format, so replicating both Reels and Stories
        // from one account takes two of them. Polling groups by account, so
        // dropping the unique costs no extra calls against Meta's quota.
        //
        // MySQL refuses to drop the only index backing the workspace_id foreign
        // key (SQLSTATE 1553), so the replacement goes in before the unique
        // comes out, in its own statement.
        Schema::table('repurposes', function (Blueprint $table) {
            $table->index(['workspace_id', 'source_social_account_id'], 'repurposes_workspace_source_index');
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'source_social_account_id']);
        });
    }

    public function down(): void
    {
        // Same constraint in reverse: the unique has to exist before the plain
        // index backing the foreign key can go.
        Schema::table('repurposes', function (Blueprint $table) {
            $table->unique(['workspace_id', 'source_social_account_id']);
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropIndex('repurposes_workspace_source_index');
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropColumn('source_format');
        });
    }
};
