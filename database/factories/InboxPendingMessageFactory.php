<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxPendingMessage;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxPendingMessage>
 */
class InboxPendingMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $postId = (string) fake()->unique()->numberBetween(1000, 999999);
        $messageId = (string) fake()->unique()->numberBetween(1000, 999999);

        return [
            'social_account_id' => SocialAccount::factory()->instagram(),
            'platform_post_id' => $postId,
            'platform_post_hash' => fn (array $attributes): string => InboxConversation::hashOf((string) $attributes['platform_post_id']),
            'platform_message_hash' => InboxConversation::hashOf($messageId),
            'payload' => fn (array $attributes): array => (new IncomingMessage(
                kind: ConversationKind::Comment,
                subject: new IncomingSubject((string) $attributes['platform_post_id'], null, [], null, null, (string) $attributes['platform_post_id']),
                platformMessageId: $messageId,
                platformParentId: null,
                direction: MessageDirection::Inbound,
                authorPlatformId: null,
                authorName: fake()->name(),
                authorUsername: fake()->userName(),
                authorAvatarUrl: null,
                body: fake()->sentence(),
                media: [],
                platformUrl: null,
                platformCreatedAt: CarbonImmutable::now(),
            ))->toArray(),
        ];
    }
}
