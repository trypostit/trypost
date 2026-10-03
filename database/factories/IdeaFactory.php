<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Dto\MediaItem;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Idea>
 */
class IdeaFactory extends Factory
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
            'user_id' => User::factory(),
            'idea_stage_id' => null,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'media' => null,
            'position' => 0,
        ];
    }

    public function inStage(IdeaStage $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'workspace_id' => $stage->workspace_id,
            'idea_stage_id' => $stage->id,
        ]);
    }

    public function withMedia(Media $asset): static
    {
        return $this->state(fn (array $attributes) => [
            'media' => [MediaItem::fromMedia($asset)->toArray()],
        ]);
    }
}
