<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stamp onboarding_dismissed_at for legacy accounts that already have app
     * access, so the new activation checklist does not appear for existing
     * customers. Mirrors Account::hasAppAccess() / Cashier subscribed() with
     * keepPastDueSubscriptionsActive enabled.
     */
    public function up(): void
    {
        $now = now();

        if (config('trypost.self_hosted')) {
            $this->stampInChunks(
                DB::table('accounts')
                    ->whereNull('onboarding_dismissed_at')
                    ->whereNull('onboarding_completed_at'),
                $now,
            );

            return;
        }

        $query = DB::table('accounts')
            ->whereNull('onboarding_dismissed_at')
            ->whereNull('onboarding_completed_at')
            ->where(function (Builder $accounts) use ($now): void {
                $accounts->whereExists(function (Builder $subscriptions) use ($now): void {
                    $subscriptions->selectRaw('1')
                        ->from('subscriptions')
                        ->whereColumn('subscriptions.account_id', 'accounts.id')
                        ->where('subscriptions.type', 'default')
                        ->where('subscriptions.id', function (Builder $latestSubscription): void {
                            $latestSubscription->select('latest.id')
                                ->from('subscriptions as latest')
                                ->whereColumn('latest.account_id', 'accounts.id')
                                ->where('latest.type', 'default')
                                ->latest('latest.created_at')
                                ->latest('latest.id')
                                ->limit(1);
                        })
                        ->where(function (Builder $valid) use ($now): void {
                            $valid
                                ->where('subscriptions.ends_at', '>', $now)
                                ->orWhere('subscriptions.trial_ends_at', '>', $now)
                                ->orWhere(function (Builder $active) use ($now): void {
                                    $active->where(function (Builder $notEnded) use ($now): void {
                                        $notEnded->whereNull('subscriptions.ends_at')
                                            ->orWhere('subscriptions.ends_at', '>', $now);
                                    })->whereNotIn('subscriptions.stripe_status', [
                                        'incomplete',
                                        'incomplete_expired',
                                        'unpaid',
                                    ]);
                                });
                        });
                });

                if (! (bool) config('trypost.billing.require_card_for_trial', true)) {
                    $accounts->orWhere('accounts.trial_ends_at', '>', $now);
                }
            });

        $this->stampInChunks($query, $now);
    }

    /**
     * Update in id-ordered chunks so a large accounts table is not locked by a
     * single statement. chunkById paginates on the id column, so rows stamped
     * by earlier chunks falling out of the WHERE is harmless.
     */
    private function stampInChunks(Builder $query, CarbonInterface $now): void
    {
        $query->chunkById(500, function ($accounts) use ($now): void {
            DB::table('accounts')
                ->whereIn('id', $accounts->pluck('id'))
                ->update([
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
