<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_publications', function (Blueprint $table) {
            $table->json('poll')->nullable()->after('provider_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_publications', function (Blueprint $table) {
            $table->dropColumn('poll');
        });
    }
};
