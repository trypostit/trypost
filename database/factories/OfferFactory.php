<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(3),
            'stripe_coupon_id' => fake()->unique()->bothify('coupon_????????'),
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'max_redemptions' => null,
            'trial_days' => null,
            'allow_promotion_codes' => false,
            'first_subscription_only' => false,
            'show_banner' => false,
        ];
    }

    public function upcoming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->addDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function limitedTo(int $places): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_redemptions' => $places,
        ]);
    }

    public function trial(int $days): static
    {
        return $this->state(fn (array $attributes): array => [
            'stripe_coupon_id' => null,
            'trial_days' => $days,
        ]);
    }

    public function allowingPromotionCodes(): static
    {
        return $this->state(fn (array $attributes): array => [
            'allow_promotion_codes' => true,
        ]);
    }

    /**
     * The Black Friday 2026 offer: 50% off the first 3 months, with its banner.
     */
    public function blackFriday(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slug' => 'bf26',
            'stripe_coupon_id' => 'BF26_50_3M',
            'show_banner' => true,
        ]);
    }

    /**
     * The $1 first month, for accounts that never subscribed.
     */
    public function firstMonth(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slug' => 'first-month',
            'stripe_coupon_id' => 'SOCIALS_18USD',
            'first_subscription_only' => true,
        ]);
    }

    /**
     * The 7-day free trial. Stripe Checkout shows 8 trial days as 7.
     */
    public function freeTrial(): static
    {
        return $this->state(fn (array $attributes): array => [
            'slug' => 'free-trial-7-days',
            'stripe_coupon_id' => null,
            'trial_days' => 8,
            'first_subscription_only' => true,
        ]);
    }

    public function withBanner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'show_banner' => true,
        ]);
    }

    public function firstSubscriptionOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_subscription_only' => true,
        ]);
    }
}
