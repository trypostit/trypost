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
            'first_subscription_only' => false,
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

    public function firstSubscriptionOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_subscription_only' => true,
        ]);
    }
}
