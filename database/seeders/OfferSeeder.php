<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Offer;
use Illuminate\Database\Seeder;

class OfferSeeder extends Seeder
{
    /**
     * Local offers to try the Welcome checkout with. Their coupons live in
     * Stripe test mode, so they are never seeded outside a local install;
     * other environments add their offers by hand.
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        Offer::updateOrCreate(
            ['slug' => 'free-trial-7-days'],
            ['starts_at' => now(), 'trial_days' => 8, 'first_subscription_only' => true],
        );

        Offer::updateOrCreate(
            ['slug' => 'bf26'],
            ['starts_at' => now(), 'stripe_coupon_id' => 'TRYPOST_TEST_BF_50_3M', 'show_banner' => true],
        );

        Offer::updateOrCreate(
            ['slug' => 'first-month'],
            ['starts_at' => now(), 'stripe_coupon_id' => 'SOCIALS_18USD', 'first_subscription_only' => true],
        );
    }
}
