<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'endpoint' => fake()->url(),
            'events' => [EventType::PostPublished->value, EventType::PostFailed->value],
            'status' => Status::Enabled,
            'signing_secret' => Webhook::generateSigningSecret(),
            'consecutive_failures' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Status::Disabled,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Status::Paused,
            'consecutive_failures' => 5,
            'paused_at' => now(),
        ]);
    }
}
