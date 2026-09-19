<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Webhook\EventType;
use App\Models\Webhook;
use App\Models\WebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookLog>
 */
class WebhookLogFactory extends Factory
{
    protected $model = WebhookLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'event_type' => EventType::PostPublished->value,
            'payload' => [
                'type' => EventType::PostPublished->value,
                'data' => [],
            ],
            'response_status' => 200,
            'response_body' => 'OK',
            'delivered_at' => now(),
            'attempts' => 1,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'response_status' => 500,
            'response_body' => 'Internal Server Error',
            'delivered_at' => null,
            'failed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'response_status' => null,
            'response_body' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'attempts' => 1,
        ]);
    }
}
