<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Offer;
use Illuminate\Database\Seeder;

class OfferSeeder extends Seeder
{
    /**
     * Local offers to try the Welcome checkout with, run by hand with
     * `php artisan db:seed --class=OfferSeeder`. Their coupons live in Stripe
     * test mode; other environments add their offers by hand.
     */
    public function run(): void
    {
        Offer::updateOrCreate(
            ['slug' => 'ft7d'],
            ['starts_at' => now(), 'trial_days' => 8, 'first_subscription_only' => true],
        );

        Offer::updateOrCreate(
            ['slug' => 'ft7dnc'],
            ['starts_at' => now(), 'trial_days' => 7, 'requires_card' => false],
        );

        Offer::updateOrCreate(
            ['slug' => 'bf26'],
            ['starts_at' => now(), 'stripe_coupon_id' => 'TRYPOST_TEST_BF_50_3M', 'show_banner' => true],
        );

        Offer::updateOrCreate(
            ['slug' => 'fm1'],
            ['starts_at' => now(), 'stripe_coupon_id' => 'SOCIALS_18USD', 'first_subscription_only' => true],
        );
    }
}
