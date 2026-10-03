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
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('schedule_mode')->nullable()->after('status');
            $table->index(['schedule_mode', 'status', 'scheduled_at']);
        });

        DB::table('posts')->where('status', 'scheduled')->update(['schedule_mode' => 'custom']);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['schedule_mode', 'status', 'scheduled_at']);
            $table->dropColumn('schedule_mode');
        });
    }
};
