<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Inbox\ConversationKind;
use App\Models\InboxConversation;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxConversation>
 */
class InboxConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = 'at://did:plc:'.fake()->lexify('????????').'/app.bsky.feed.post/'.fake()->lexify('?????????????');

        return [
            'social_account_id' => SocialAccount::factory()->bluesky(),
            'workspace_id' => fn (array $attributes) => SocialAccount::find($attributes['social_account_id'])->workspace_id,
            'kind' => ConversationKind::Comment,
            'platform_conversation_id' => $id,
            'platform_conversation_hash' => InboxConversation::hashOf($id),
            'subject_text' => fake()->sentence(),
            'subject_url' => fake()->url(),
            'subject_published_at' => now()->subDay(),
            'last_message_at' => now(),
        ];
    }

    public function linkedTo(Post $post): static
    {
        return $this->state(fn () => [
            'social_account_id' => $post->social_account_id,
            'workspace_id' => $post->workspace_id,
            'platform_conversation_id' => $post->platform_post_id,
            'platform_conversation_hash' => InboxConversation::hashOf((string) $post->platform_post_id),
            'post_id' => $post->id,
        ]);
    }
}
