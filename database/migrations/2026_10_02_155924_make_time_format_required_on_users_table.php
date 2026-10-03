<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users without a chosen clock get the one they already see: English a
     * 12-hour clock, every other language a 24-hour one.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('time_format')
            ->where('locale', Locale::English->value)
            ->update(['time_format' => TimeFormat::TwelveHour->value]);

        DB::table('users')
            ->whereNull('time_format')
            ->update(['time_format' => TimeFormat::TwentyFourHour->value]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('time_format', 8)->default(TimeFormat::DEFAULT->value)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('time_format', 8)->nullable()->default(null)->change();
        });
    }
};
