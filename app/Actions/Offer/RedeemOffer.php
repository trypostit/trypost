<?php

declare(strict_types=1);

namespace App\Actions\Offer;

use App\Models\Account;
use App\Models\Offer;
use Illuminate\Support\Str;

class RedeemOffer
{
    /**
     * Records that the account's subscription was created with the offer.
     * Only the first redemption counts. A default offer was never claimed, so
     * its redemption is the account's first row for it.
     */
    public static function execute(Account $account, ?string $offerId): void
    {
        if (! Str::isUuid($offerId)) {
            return;
        }

        $claimed = $account->offers()->find($offerId);

        if (filled($claimed)) {
            if (blank($claimed->pivot->redeemed_at)) {
                $account->offers()->updateExistingPivot($offerId, ['redeemed_at' => now()]);
            }

            return;
        }

        if (Offer::query()->whereKey($offerId)->exists()) {
            $account->offers()->attach($offerId, ['claimed_at' => now(), 'redeemed_at' => now()]);
        }
    }
}
