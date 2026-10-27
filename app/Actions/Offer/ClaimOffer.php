<?php

declare(strict_types=1);

namespace App\Actions\Offer;

use App\Models\Account;
use App\Models\Offer;
use Illuminate\Database\UniqueConstraintViolationException;

class ClaimOffer
{
    /**
     * Ties an available offer to an account without an active subscription.
     * An unknown, unavailable or already redeemed offer is ignored. A request
     * racing another one for the same claim finds it already written.
     */
    public static function execute(Account $account, string $slug): ?Offer
    {
        if ($account->hasActiveSubscription()) {
            return null;
        }

        $offer = Offer::findAvailable($slug, $account);

        if (blank($offer)) {
            return null;
        }

        try {
            $account->offers()->syncWithoutDetaching([$offer->id => ['claimed_at' => now()]]);
        } catch (UniqueConstraintViolationException) {
            return $offer;
        }

        return $offer;
    }
}
