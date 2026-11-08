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
            $table->unsignedInteger('seen_generation')->default(0)->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->dropColumn('seen_generation');
        });
    }
};
