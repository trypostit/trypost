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

        Schema::table('repurposes', function (Blueprint $table) {
            $table->unique(
                ['workspace_id', 'source_social_account_id', 'source_format'],
                'repurposes_source_format_unique',
            );
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'source_social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('repurposes', function (Blueprint $table) {
            $table->unique(['workspace_id', 'source_social_account_id']);
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropUnique('repurposes_source_format_unique');
        });

        Schema::table('repurposes', function (Blueprint $table) {
            $table->dropColumn('source_format');
        });
    }
};
