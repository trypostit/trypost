<?php

declare(strict_types=1);

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Stamp onboarding_completed_at for every account that already existed when
     * the activation checklist shipped, so upgrades (SaaS or self-hosted) do
     * not suddenly surface the new funnel. Fresh installs run this before any
     * account exists — the first owner created afterward still sees it.
     */
    public function up(): void
    {
        $now = now();

        Account::query()
            ->whereNull('onboarding_dismissed_at')
            ->whereNull('onboarding_completed_at')
            ->update([
                'onboarding_completed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        Account::query()
            ->whereNull('onboarding_dismissed_at')
            ->whereNotNull('onboarding_completed_at')
            ->update([
                'onboarding_completed_at' => null,
                'updated_at' => now(),
            ]);
    }
};
