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

        // A repurpose watches one format, so replicating both Reels and feed
        // videos from one account takes two of them. Polling groups by account,
        // so this costs no extra calls against Meta's quota.
        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'source_social_account_id']);
            $table->index(['workspace_id', 'source_social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'source_social_account_id']);
            $table->unique(['workspace_id', 'source_social_account_id']);
            $table->dropColumn('source_format');
        });
    }
};
