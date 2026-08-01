<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use Illuminate\Support\Facades\Log;
use Throwable;

class CancelAccountSubscription
{
    /**
     * Cancel the non-ended named Stripe subscription for an account before
     * local teardown.
     *
     * @return bool false when Stripe cancel fails (nothing local should be deleted)
     */
    public static function execute(Account $account): bool
    {
        try {
            $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

            if ($subscription && ! $subscription->ended()) {
                $subscription->cancelNow();
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to cancel Stripe subscription during account delete', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
