<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('lifecycle_stage')->nullable();
            $table->timestamp('lifecycle_stage_changed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('first_paid_at')->nullable();
        });

        $this->backfillFirstPaidAt();
    }

    /**
     * Mark the accounts already charged before `first_paid_at` existed: any
     * subscription past its checkout and its trial. A past due one whose trial
     * ended within a month failed its first charge and is not paid. A trial
     * cancelled before this deploy has lost its `trial_ends_at` to Cashier and
     * counts as paid.
     */
    public function backfillFirstPaidAt(): void
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_stage', 'lifecycle_stage_changed_at', 'activated_at', 'first_paid_at']);
        });
    }
};
