<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mark the accounts already charged before `first_paid_at` existed: any
     * subscription past its checkout and its trial. A past due one whose trial
     * ended within a month failed its first charge and is not paid. A trial
     * cancelled before this deploy has lost its `trial_ends_at` to Cashier and
     * counts as paid.
     */
    public function up(): void
    {
        DB::table('subscriptions')
            ->select('account_id', DB::raw('min(created_at) as first_paid_at'))
            ->whereNotIn('stripe_status', ['incomplete', 'incomplete_expired', 'trialing'])
            ->where(fn (Builder $query) => $query
                ->whereNotIn('stripe_status', ['past_due', 'unpaid'])
                ->orWhereNull('trial_ends_at')
                ->orWhere('trial_ends_at', '<', now()->subMonth()))
            ->whereNot(fn (Builder $query) => $query
                ->whereNotNull('ends_at')
                ->whereNotNull('trial_ends_at')
                ->whereColumn('ends_at', '<=', 'trial_ends_at'))
            ->groupBy('account_id')
            ->orderBy('account_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('accounts')->where('id', $row->account_id)->whereNull('first_paid_at')->update(['first_paid_at' => $row->first_paid_at]);
                }
            });
    }
};
