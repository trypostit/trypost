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
        $offer = Offer::available()->where('slug', $slug)->first();

        if (blank($offer) || $offer->slug !== $slug || $account->hasActiveSubscription()) {
            return null;
        }

        if ($account->offers()->whereKey($offer->id)->wherePivotNotNull('redeemed_at')->exists()) {
            return null;
        }

        $account->offers()->newPivotStatement()->upsert(
            [['account_id' => $account->id, 'offer_id' => $offer->id, 'claimed_at' => now()]],
            ['account_id', 'offer_id'],
            ['claimed_at'],
        );

        return $offer;
    }
}
