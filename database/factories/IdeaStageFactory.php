<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IdeaStage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdeaStage>
 */
class IdeaStageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->words(2, true),
            'position' => 0,
        ];
    }
}
