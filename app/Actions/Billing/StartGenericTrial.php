<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Offer\RedeemOffer;
use App\Enums\Billing\Interval;
use App\Enums\PostHog\TrialEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Jobs\PostHog\SyncUser;
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
     * billing to keep it. A second submit while it runs starts nothing.
     */
    public static function execute(User $user, Plan $plan, Offer $offer): void
    {
        $account = DB::transaction(function () use ($user, $plan, $offer): ?Account {
            $account = Account::query()->lockForUpdate()->find($user->account_id);

            if ($account->onGenericTrial() || $account->subscribed(Account::SUBSCRIPTION_NAME)) {
                return null;
            }

            $account->forceFill([
                'plan_id' => $plan->id,
                'trial_ends_at' => now()->addDays($offer->trial_days),
            ])->save();

            RedeemOffer::execute($account, $offer->id);

            return $account;
        });

        if (blank($account)) {
            return;
        }

        rescue(function () use ($user, $account, $plan, $offer): void {
            SyncUser::dispatchIf(PostHogService::isEnabled(), (string) $user->id);
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
