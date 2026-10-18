<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\Sort;
use App\Enums\Inbox\StatusFilter;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class ListInboxMessages
{
    /**
     * Top-level comments whose thread has a message in the range and status:
     * the comment itself or any message under it, so an open follow-up under
     * an answered comment is still found by Unanswered.
     *
     * @return Builder<InboxMessage>
     */
    public static function execute(Workspace $workspace, InboxFilters $filters): Builder
    {
        $matches = function (Builder $messages) use ($filters): void {
            $messages->notRemoved()->where('platform_created_at', '>=', $filters->range->since());

            match ($filters->status) {
                StatusFilter::All => null,
                StatusFilter::Unanswered => $messages->open(),
                StatusFilter::Replied => $messages->whereNotNull('answered_at'),
                StatusFilter::Resolved => $messages->whereNotNull('resolved_at'),
            };
        };

        $query = InboxMessage::query()
            ->whereNull('parent_id')
            ->notRemoved()
            ->where('direction', MessageDirection::Inbound->value)
            ->where(fn (Builder $thread) => $thread->where($matches)->orWhereHas('treeMessages', $matches))
            ->whereHas('conversation', function (Builder $conversations) use ($workspace, $filters) {
                $conversations->visibleInTab($workspace, $filters->tab);

                if (filled($filters->channelIds)) {
                    $conversations->whereIn('social_account_id', $filters->channelIds);
                }
            })
            ->with([
                'conversation.socialAccount',
                'conversation.post:id,origin,user_id,created_at,media,content,platform_url,published_at',
                'conversation.post.user:id,name',
                'treeMessages' => fn ($tree) => $tree->notRemoved()->orderBy('platform_created_at')->orderBy('id'),
                'treeMessages.sentBy',
                'sentBy',
            ]);

        match ($filters->sort) {
            Sort::UnansweredFirst => $query->orderByDesc(
                InboxConversation::query()->select('open_count')->whereColumn('inbox_conversations.id', 'inbox_messages.inbox_conversation_id')
            )->orderByDesc('platform_created_at'),
            Sort::Newest => $query->orderByDesc('platform_created_at'),
            Sort::Oldest => $query->orderBy('platform_created_at'),
        };

        return $query->orderByDesc('id');
    }

    /**
     * The page of the list holding a message's thread (a reply is found
     * under its top-level comment), in the list's own order, so a reload
     * lands where the user was. Null when the list does not show it.
     */
    public static function pageOf(Workspace $workspace, InboxFilters $filters, string $messageId, int $perPage): ?int
    {
        $message = InboxMessage::query()->find($messageId, ['id', 'root_id']);

        if (! $message) {
            return null;
        }

        $position = self::execute($workspace, $filters)
            ->setEagerLoads([])
            ->pluck('inbox_messages.id')
            ->search($message->root_id ?? $message->id);

        return $position === false ? null : intdiv($position, max(1, $perPage)) + 1;
    }
}
