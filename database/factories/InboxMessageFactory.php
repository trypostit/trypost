<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Post\Origin;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxMessage>
 */
class InboxMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = 'at://did:plc:'.fake()->lexify('????????').'/app.bsky.feed.post/'.fake()->lexify('?????????????');

        return [
            'inbox_conversation_id' => InboxConversation::factory(),
            'social_account_id' => fn (array $attributes) => InboxConversation::find($attributes['inbox_conversation_id'])->social_account_id,
            'platform_message_id' => $id,
            'platform_message_hash' => InboxConversation::hashOf($id),
            'direction' => MessageDirection::Inbound,
            'origin' => Origin::Network,
            'author_platform_id' => 'did:plc:'.fake()->lexify('????????'),
            'author_name' => fake()->name(),
            'author_username' => fake()->userName().'.bsky.social',
            'body' => fake()->sentence(),
            'platform_created_at' => now()->subHour(),
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Outbound,
            'delivery_status' => DeliveryStatus::Sent,
        ]);
    }

    public function sentFromTryPost(): static
    {
        return $this->outbound()->state(fn () => ['origin' => Origin::TryPost]);
    }

    public function answered(): static
    {
        return $this->state(fn () => ['answered_at' => now()]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()]);
    }
}
