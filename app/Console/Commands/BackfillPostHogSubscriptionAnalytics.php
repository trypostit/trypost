<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PostHog\HydrateSubscriptionAnalytics;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('posthog:backfill-subscription-analytics')]
#[Description('Queue subscription analytics synchronization for every PostHog account group')]
class BackfillPostHogSubscriptionAnalytics extends Command
{
    public function handle(): int
    {
        if (! PostHogService::isEnabled()) {
            $this->components->warn('PostHog is disabled; no accounts were queued.');

            return self::SUCCESS;
        }

        $accountsQueued = 0;
        $subscriptionsQueued = 0;

        Account::query()
            ->select('id')
            ->with(['subscriptions' => fn ($query) => $query
                ->where('type', Account::SUBSCRIPTION_NAME)
                ->where('stripe_status', 'active')
                ->select('id', 'account_id')])
            ->lazyById()
            ->each(function (Account $account) use (&$accountsQueued, &$subscriptionsQueued): void {
                SyncAccountUsage::dispatch((string) $account->id);
                $accountsQueued++;

                $subscription = $account->subscriptions->first();

                if ($subscription) {
                    HydrateSubscriptionAnalytics::dispatch((string) $subscription->id);
                    $subscriptionsQueued++;
                }
            });

        $this->components->info(
            "Queued subscription analytics sync for {$accountsQueued} accounts; {$subscriptionsQueued} active subscriptions require Stripe hydration."
        );

        return self::SUCCESS;
    }
}
