<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_sync_states', function (Blueprint $table) {
            $table->string('error_category', 32)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_sync_states', function (Blueprint $table) {
            $table->dropColumn('error_category');
        });
    }
};
