<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentRating;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentRating>
 */
class ContentRatingFactory extends Factory
{
    protected $model = ContentRating::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'rating' => fake()->numberBetween(1, 5),
        ];
    }
}
