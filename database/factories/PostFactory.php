<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Post\PublishMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
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
            'content' => '',
            'media' => [],
            'status' => PostStatus::Draft,
            'publish_mode' => PublishMode::Auto,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'publish_mode' => PublishMode::Manual,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Draft,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Failed,
        ]);
    }
}
