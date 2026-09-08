<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Plan\Slug;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => Slug::Socials,
            'name' => 'Socials',
            'stripe_monthly_price_id' => null,
            'stripe_yearly_price_id' => null,
            'workspace_limit' => 1,
            'sort' => 0,
            'is_archived' => false,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'workspace_limit' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_archived' => true,
        ]);
    }
}
