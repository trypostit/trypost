<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('recurrence_interval')->nullable()->after('schedule_mode');
            $table->string('recurrence_frequency')->nullable()->after('recurrence_interval');
            $table->unsignedInteger('recurrence_remaining')->nullable()->after('recurrence_frequency');
            $table->timestamp('recurrence_anchor_at')->nullable()->after('recurrence_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn(['recurrence_interval', 'recurrence_frequency', 'recurrence_remaining', 'recurrence_anchor_at']);
        });
    }
};
