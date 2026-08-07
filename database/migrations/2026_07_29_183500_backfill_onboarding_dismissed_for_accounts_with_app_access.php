<?php

declare(strict_types=1);

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Stamp onboarding_dismissed_at for legacy SaaS accounts that already have
     * app access, so the new activation checklist does not appear for existing
     * customers. Skipped in self-hosted — every account has app access there,
     * and we still want the checklist for a first-run experience.
     */
    public function up(): void
    {
        if (config('trypost.self_hosted')) {
            return;
        }

        $now = now();

        Account::query()
            ->with('subscriptions')
            ->whereNull('onboarding_dismissed_at')
            ->whereNull('onboarding_completed_at')
            ->chunkById(500, function ($accounts) use ($now): void {
                $ids = $accounts->filter->hasAppAccess()->modelKeys();

                if ($ids === []) {
                    return;
                }

                Account::query()->whereKey($ids)->update([
                    'onboarding_dismissed_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        // Data backfill — rows stamped here are indistinguishable from a real
        // user skip, so a rollback intentionally keeps them.
    }
};
