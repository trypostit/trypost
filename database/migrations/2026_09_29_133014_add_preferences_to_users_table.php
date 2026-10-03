<?php

declare(strict_types=1);

use App\Enums\User\DefaultPostAction;
use App\Enums\User\Theme;
use App\Enums\User\WeekStart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone')->default('UTC')->after('locale');
            $table->string('theme', 16)->default(Theme::DEFAULT->value)->after('timezone');
            $table->string('time_format', 8)->nullable()->after('theme');
            $table->string('week_starts_on', 16)->default(WeekStart::DEFAULT->value)->after('time_format');
            $table->string('default_post_action', 16)->default(DefaultPostAction::DEFAULT->value)->after('week_starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'theme', 'time_format', 'week_starts_on', 'default_post_action']);
        });
    }
};
