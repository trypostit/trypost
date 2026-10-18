<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxConversation;
use App\Models\Post;
use App\Models\SocialAccount;

/**
 * Links a comment or mention conversation to the post of its channel the
 * comments are under, by the id the network gives that post: TryPost's own
 * posts and the ones imported from the network both carry it in
 * posts.platform_post_id. Bluesky threads are keyed by the post's AT URI,
 * which ends with the record key the post stores.
 */
class LinkInboxConversationPost
{
    private const array KINDS = [ConversationKind::Comment, ConversationKind::Mention];

    /**
     * The post the incoming message's subject is, read once when its
     * conversation is stored.
     */
    public static function forSubject(SocialAccount $account, IncomingMessage $incoming): ?string
    {
        if (! in_array($incoming->kind, self::KINDS, true)) {
            return null;
        }

        return Post::query()
            ->where('social_account_id', $account->id)
            ->where('platform_post_id', self::remoteIdOf($account, $incoming->subject))
            ->value('id');
    }

    /**
     * Links the channel's unlinked conversations about this post, once the
     * post learns its network id (published or imported after the comments).
     */
    public static function toPost(Post $post): void
    {
        if (blank($post->social_account_id) || blank($post->platform_post_id)) {
            return;
        }

        $account = $post->relationLoaded('socialAccount') ? $post->socialAccount : SocialAccount::query()->find($post->social_account_id);

        if (! $account) {
            return;
        }

        InboxConversation::query()
            ->where('social_account_id', $account->id)
            ->whereIn('kind', array_map(fn (ConversationKind $kind): string => $kind->value, self::KINDS))
            ->whereNull('post_id')
            ->whereIn('platform_conversation_hash', array_map(
                fn (string $id): string => InboxConversation::hashOf($id),
                self::conversationIds($account, (string) $post->platform_post_id),
            ))
            ->update(['post_id' => $post->id]);
    }

    /**
     * The id the post carries in posts.platform_post_id and the publication
     * in analytics_publications.remote_id.
     */
    public static function remoteIdOf(SocialAccount $account, IncomingSubject $subject): string
    {
        if (filled($subject->remoteId)) {
            return $subject->remoteId;
        }

        return $account->platform === Platform::Bluesky && str_starts_with($subject->platformId, 'at://')
            ? basename($subject->platformId)
            : $subject->platformId;
    }

    /**
     * @return list<string>
     */
    private static function conversationIds(SocialAccount $account, string $platformPostId): array
    {
        if ($account->platform === Platform::Bluesky && filled($account->platform_user_id)) {
            return [$platformPostId, "at://{$account->platform_user_id}/app.bsky.feed.post/{$platformPostId}"];
        }

        return [$platformPostId];
    }
}
