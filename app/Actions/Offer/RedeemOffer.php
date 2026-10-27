<?php

declare(strict_types=1);

namespace App\Actions\Offer;

use App\Models\Account;
use Illuminate\Support\Str;

class RedeemOffer
{
    /**
     * Records that the account used the offer it claimed: its subscription
     * became active or trialing with it, or its trial without a card started.
     * Only the first redemption counts; an offer the account never claimed is
     * ignored. The redemption is what makes an offer a first subscription
     * (`Account::isFirstTimeSubscriber()`).
     */
    public static function execute(Account $account, ?string $offerId): void
    {
        if (! Str::isUuid($offerId)) {
            return;
        }

        $account->offers()
            ->wherePivotNull('redeemed_at')
            ->updateExistingPivot($offerId, ['redeemed_at' => now()]);
    }
}
