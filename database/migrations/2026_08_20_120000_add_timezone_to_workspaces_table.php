<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // IANA identifier (e.g. "Australia/Brisbane"). Null = the viewer's
            // browser timezone, the pre-setting behavior.
            $table->string('timezone', 64)->nullable()->after('content_language');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
