<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use Illuminate\Support\Collection;

class CancelAccounts
{
    /**
     * Cancel Stripe for each account in order. Stops on the first failure.
     *
     * Callers should order accounts so a mid-loop failure leaves the most
     * important subscription intact (e.g. member personals before the shared account).
     *
     * @param  Collection<int, Account>|iterable<int, Account>  $accounts
     * @return bool false when any cancel failed — local teardown must not proceed
     */
    public static function execute(iterable $accounts): bool
    {
        foreach ($accounts as $account) {
            if (! CancelAccountSubscription::execute($account)) {
                return false;
            }
        }

        return true;
    }
}
