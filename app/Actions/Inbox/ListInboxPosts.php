<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\Sort;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxConversation;
use App\Models\Post;
use App\Models\Workspace;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The "Show all posts" list of By post: the list's comment conversations
 * plus the published posts of the channels with comments that nobody has
 * commented on yet, each as a conversation not stored (no id, no comments).
 */
class ListInboxPosts
{
    /**
     * The list's rows: every conversation By post lists, then every post
     * nobody has commented on yet, ordered together by the list's sort.
     */
    public static function query(Workspace $workspace, InboxFilters $filters): QueryBuilder
    {
        $conversations = ListInboxConversations::execute($workspace, $filters, [ConversationKind::Comment])
            ->reorder()
            ->toBase()
            ->select([
                'inbox_conversations.id as conversation_id',
                DB::raw('NULL as post_id'),
                'inbox_conversations.open_count as list_open_count',
                'inbox_conversations.last_message_at as list_at',
                'inbox_conversations.id as list_key',
            ]);

        $posts = Post::query()
            ->where('posts.workspace_id', $workspace->id)
            ->published()
            ->whereHas('socialAccount', fn (Builder $accounts) => $accounts->whereIn('platform', self::platforms()))
            ->onChannels(filled($filters->channelIds) ? $filters->channelIds : null)
            ->where('posts.published_at', '>=', $filters->range->since())
            ->whereNotExists(self::conversations($workspace)->toBase())
            ->toBase()
            ->select([
                DB::raw('NULL as conversation_id'),
                'posts.id as post_id',
                DB::raw('0 as list_open_count'),
                'posts.published_at as list_at',
                'posts.id as list_key',
            ]);

        $query = $conversations->union($posts);

        match ($filters->sort) {
            Sort::UnansweredFirst => $query->orderByDesc('list_open_count')->orderByDesc('list_at'),
            Sort::Newest => $query->orderByDesc('list_at'),
            Sort::Oldest => $query->orderBy('list_at'),
        };

        return $query->orderByDesc('list_key');
    }

    /**
     * @return LengthAwarePaginator<int, InboxConversation>
     */
    public static function paginate(Workspace $workspace, InboxFilters $filters, int $perPage, ?int $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        $rows = self::query($workspace, $filters)->paginate($perPage, pageName: $pageName, page: $page);
        $conversations = self::loadConversations($rows->getCollection()->pluck('conversation_id')->filter()->values()->all());
        $posts = Post::query()
            ->whereKey($rows->getCollection()->pluck('post_id')->filter()->values()->all())
            ->with(['socialAccount', 'user:id,name'])
            ->get()
            ->keyBy(fn (Post $post): string => (string) $post->id);

        return $rows->through(fn (object $row): ?InboxConversation => filled($row->conversation_id)
            ? $conversations->get((string) $row->conversation_id)
            : transform($posts->get((string) $row->post_id), self::unstored(...)));
    }

    public static function first(Workspace $workspace, InboxFilters $filters): ?InboxConversation
    {
        return self::paginate($workspace, $filters, 1)->first();
    }

    /**
     * The post's comment conversation, or one not stored yet while it has no
     * comments; null when the post is not one the list can show.
     */
    public static function forPost(Workspace $workspace, string $postId): ?InboxConversation
    {
        $conversation = InboxConversation::query()
            ->visibleInTab($workspace, InboxTab::Comments)
            ->where('kind', ConversationKind::Comment->value)
            ->where('post_id', $postId)
            ->orderByDesc('last_message_at')
            ->first();

        if (filled($conversation)) {
            return $conversation;
        }

        $post = Post::query()
            ->whereKey($postId)
            ->where('posts.workspace_id', $workspace->id)
            ->published()
            ->whereHas('socialAccount', fn (Builder $accounts) => $accounts->whereIn('platform', self::platforms()))
            ->with(['socialAccount', 'user:id,name'])
            ->first();

        return transform($post, self::unstored(...));
    }

    /**
     * The networks whose driver reads comments; a channel with reviews only
     * has no post with comments.
     *
     * @return list<string>
     */
    private static function platforms(): array
    {
        $drivers = app(InboxDrivers::class);

        return array_values(array_filter(
            Platform::inboxValues(),
            fn (string $value): bool => in_array(ConversationKind::Comment, $drivers->for(Platform::from($value))?->kinds() ?? [], true),
        ));
    }

    /**
     * The comment conversations of the outer query's post.
     *
     * @return Builder<InboxConversation>
     */
    private static function conversations(Workspace $workspace): Builder
    {
        return InboxConversation::query()
            ->whereColumn('inbox_conversations.post_id', 'posts.id')
            ->where('inbox_conversations.workspace_id', $workspace->id)
            ->where('inbox_conversations.kind', ConversationKind::Comment->value);
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<string, InboxConversation>
     */
    private static function loadConversations(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return InboxConversation::query()
            ->whereKey($ids)
            ->with(['socialAccount', 'post:id,origin,user_id,created_at,media,content,platform_url,published_at', 'post.user:id,name'])
            ->get()
            ->keyBy(fn (InboxConversation $conversation): string => (string) $conversation->id);
    }

    private static function unstored(Post $post): InboxConversation
    {
        $conversation = (new InboxConversation)->forceFill([
            'workspace_id' => $post->workspace_id,
            'social_account_id' => $post->social_account_id,
            'kind' => ConversationKind::Comment,
            'post_id' => $post->id,
            'open_count' => 0,
            'message_count' => 0,
            'inbound_count' => 0,
        ]);

        $conversation->setAttribute('replied_count', 0);

        return $conversation->setRelation('socialAccount', $post->socialAccount)->setRelation('post', $post);
    }
}
