<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Inertia\Inertia;

class FlashAffiliateSignup
{
    /**
     * Hands the new user to the Affonso pixel on the next page, which records
     * them as a lead of the affiliate whose referral cookie the browser holds.
     */
    public function handle(Registered $event): void
    {
        if (blank(config('services.affonso.id'))) {
            return;
        }

        if (! $event->user instanceof User) {
            return;
        }

        Inertia::flash('affiliateSignup', [
            'email' => $event->user->email,
            'externalUserId' => $event->user->id,
        ]);
    }
}
