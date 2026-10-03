<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PostTemplate\Visibility;
use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTemplate>
 */
class PostTemplateFactory extends Factory
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
            'visibility' => Visibility::Team,
            'emoji' => '📝',
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'body' => '• '.fake()->sentence()."\n• ".fake()->sentence(),
        ];
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => Visibility::Team,
        ]);
    }

    public function personal(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => Visibility::Personal,
            'user_id' => $user->id,
        ]);
    }
}
