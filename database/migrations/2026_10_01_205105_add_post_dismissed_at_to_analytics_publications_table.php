<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->timestamp('post_dismissed_at')->nullable()->after('post_platform_id');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->dropColumn('post_dismissed_at');
        });
    }
};
