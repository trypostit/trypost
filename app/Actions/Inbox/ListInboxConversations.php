<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\Sort;
use App\Enums\Inbox\StatusFilter;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ListInboxConversations
{
    /**
     * @param  list<ConversationKind>|null  $kinds  narrows the tab's kinds
     * @return Builder<InboxConversation>
     */
    public static function execute(Workspace $workspace, InboxFilters $filters, ?array $kinds = null): Builder
    {
        $query = InboxConversation::query()
            ->visibleInTab($workspace, $filters->tab)
            ->when(filled($kinds), fn (Builder $conversations) => $conversations->whereIn('kind', array_map(fn (ConversationKind $kind): string => $kind->value, $kinds)))
            ->where('last_message_at', '>=', $filters->range->since())
            ->with($filters->tab === InboxTab::Messages ? ['socialAccount', 'latestMessage'] : ['socialAccount', 'post:id,origin,user_id,created_at,media,content,platform_url,published_at', 'post.user:id,name']);

        if ($filters->tab === InboxTab::Messages) {
            $query->withExists(InboxConversation::unresolvedInboundCheck());
        }

        if (filled($filters->channelIds)) {
            $query->whereIn('social_account_id', $filters->channelIds);
        }

        match ($filters->status) {
            StatusFilter::All => null,
            StatusFilter::Unanswered => $query->where('open_count', '>', 0),
            StatusFilter::Replied => $query->whereHas('messages', fn (Builder $messages) => self::inbound($messages)->whereNotNull('answered_at')),
            StatusFilter::Resolved => $query->whereHas('messages', fn (Builder $messages) => self::inbound($messages)->whereNotNull('resolved_at')),
        };

        match ($filters->sort) {
            Sort::UnansweredFirst => $query->orderByDesc('open_count')->orderByDesc('last_message_at'),
            Sort::Newest => $query->orderByDesc('last_message_at'),
            Sort::Oldest => $query->orderBy('last_message_at'),
        };

        return $query->orderByDesc('id');
    }

    /**
     * One page of the list: the conversations, or with "Show all posts" every
     * published post of the Comments tab as its conversation.
     *
     * @param  list<ConversationKind>|null  $kinds  narrows the tab's kinds
     * @return LengthAwarePaginator<int, InboxConversation>
     */
    public static function paginate(Workspace $workspace, InboxFilters $filters, ?array $kinds = null, ?int $page = null): LengthAwarePaginator
    {
        $perPage = (int) config('app.pagination.default');

        return $filters->listsAllPosts()
            ? ListInboxPosts::paginate($workspace, $filters, $perPage, $page)
            : self::execute($workspace, $filters, $kinds)->paginate($perPage, page: $page);
    }

    public static function first(Workspace $workspace, InboxFilters $filters, ?array $kinds = null): ?InboxConversation
    {
        return $filters->listsAllPosts()
            ? ListInboxPosts::first($workspace, $filters)
            : self::execute($workspace, $filters, $kinds)->first();
    }

    /**
     * @param  Builder<InboxMessage>  $messages
     * @return Builder<InboxMessage>
     */
    private static function inbound(Builder $messages): Builder
    {
        return $messages->where('direction', MessageDirection::Inbound->value);
    }
}
