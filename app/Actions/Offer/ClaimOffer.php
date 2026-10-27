<?php

declare(strict_types=1);

namespace App\Actions\Offer;

use App\Models\Account;
use App\Models\Offer;

class ClaimOffer
{
    /**
     * Ties an available offer to an account without an active subscription.
     * An unknown, unavailable or already redeemed offer is ignored.
     */
    public static function execute(Account $account, string $slug): ?Offer
    {
        if ($account->hasActiveSubscription()) {
            return null;
        }

        $offer = Offer::findAvailable($slug);

        if (blank($offer)) {
            return null;
        }

        if ($offer->redemptions()->whereKey($account->id)->exists()) {
            return null;
        }

        $account->offers()->syncWithoutDetaching([$offer->id => ['claimed_at' => now()]]);

        return $offer;
    }
}
