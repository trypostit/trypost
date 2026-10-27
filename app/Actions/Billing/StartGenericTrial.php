<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Offer\RedeemOffer;
use App\Enums\Billing\Interval;
use App\Enums\PostHog\TrialEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;

class StartGenericTrial
{
    /**
     * Starts an offer's trial without a card: Cashier's generic trial on
     * `accounts.trial_ends_at`, with nothing created in Stripe. The account
     * has app access until it ends, and subscribes through Checkout from
     * billing to keep it.
     */
    public static function execute(User $user, Plan $plan, Offer $offer): void
    {
        $account = $user->account;

        DB::transaction(function () use ($account, $plan, $offer): void {
            $account->forceFill([
                'plan_id' => $plan->id,
                'trial_ends_at' => now()->addDays($offer->trial_days),
            ])->save();

            RedeemOffer::execute($account, $offer->id);
        });

        rescue(function () use ($user, $account, $plan, $offer): void {
            CaptureAccountEvent::dispatchIf(
                PostHogService::isEnabled(),
                (string) $user->id,
                (string) $account->id,
                TrialEvent::Started,
                [
                    'plan_name' => $plan->name,
                    'interval' => Interval::Monthly->value,
                    'offer' => $offer->slug,
                    'trial_ends_at' => $account->trial_ends_at?->toIso8601String(),
                ],
            );
        });
    }
}
