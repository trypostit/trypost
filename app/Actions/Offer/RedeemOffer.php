<?php

declare(strict_types=1);

namespace App\Actions\Offer;

use App\Models\Account;

class RedeemOffer
{
    /**
     * Records that the account's subscription was created with the offer's
     * coupon. Only the first redemption counts; an offer the account never
     * claimed is ignored.
     */
    public static function execute(Account $account, ?string $offerId): void
    {
        if (blank($offerId)) {
            return;
        }

        $account->offers()
            ->wherePivotNull('redeemed_at')
            ->updateExistingPivot($offerId, ['redeemed_at' => now()]);
    }
}
