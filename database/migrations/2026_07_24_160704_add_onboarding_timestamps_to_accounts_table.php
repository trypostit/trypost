<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('trial_ends_at');
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('onboarding_completed_at');
            $table->json('onboarding_skipped_steps')->nullable()->after('onboarding_dismissed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['onboarding_completed_at', 'onboarding_dismissed_at', 'onboarding_skipped_steps']);
        });
    }
};
