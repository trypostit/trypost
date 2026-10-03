<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Post\Origin;
use App\Enums\Post\ScheduleMode;
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
        ];
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
            'schedule_mode' => ScheduleMode::Custom,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::PendingApproval,
            'schedule_mode' => ScheduleMode::Custom,
            'scheduled_at' => now()->addDay(),
            'approval_requested_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => Origin::Network,
            'user_id' => null,
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
