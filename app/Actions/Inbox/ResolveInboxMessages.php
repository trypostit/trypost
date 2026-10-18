<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ResolveScope;
use App\Enums\SocialAccount\Platform;
use App\Events\InboxUpdated;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResolveInboxMessages
{
    /**
     * @param  list<string>  $channelIds  with all or older, only these channels; empty means every channel
     */
    public static function execute(
        Workspace $workspace,
        User $user,
        ResolveScope $scope,
        ?InboxMessage $message = null,
        ?InboxConversation $conversation = null,
        array $channelIds = [],
        InboxTab $tab = InboxTab::DEFAULT,
    ): int {
        $query = InboxMessage::query()
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNull('resolved_at')
            ->whereNull('removed_at')
            ->whereHas('conversation', fn (Builder $conversations) => $conversations->where('workspace_id', $workspace->id))
            ->whereHas('socialAccount', fn (Builder $accounts) => $accounts->whereIn('platform', Platform::inboxValues()));

        $rootId = $message ? ($message->root_id ?? $message->id) : null;

        match ($scope) {
            ResolveScope::Message => $query->where('id', $message?->id),
            ResolveScope::Thread => $query->where(fn (Builder $tree) => $tree->where('id', $rootId)->orWhere('root_id', $rootId)),
            ResolveScope::Conversation => $query->where('inbox_conversation_id', $conversation?->id),
            ResolveScope::All => $query->open(),
            ResolveScope::Older => $query->open()->where('platform_created_at', '<', now()->subMonth()),
        };

        if (in_array($scope, [ResolveScope::All, ResolveScope::Older], true)) {
            $query->whereHas('conversation', fn (Builder $conversations) => $conversations->visibleInTab($workspace, $tab));

            if (filled($channelIds)) {
                $query->whereIn('social_account_id', $channelIds);
            }
        }

        [$resolved, $conversationIds] = DB::transaction(function () use ($query, $user): array {
            $messages = $query->get(['id', 'inbox_conversation_id']);
            $conversationIds = $messages->pluck('inbox_conversation_id')->unique()->values();

            if ($messages->isEmpty()) {
                return [0, $conversationIds];
            }

            $resolved = $messages->pluck('id')->chunk(1000)->sum(fn (Collection $ids): int => InboxMessage::query()
                ->whereKey($ids->all())
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolved_by_user_id' => $user->id]));

            InboxConversation::query()->whereIn('id', $conversationIds)->orderBy('id')->get()
                ->each(fn (InboxConversation $touched) => RecountInboxConversation::execute($touched));

            return [$resolved, $conversationIds];
        });

        if ($conversationIds->isEmpty()) {
            return 0;
        }

        InboxUpdated::dispatch($workspace->id, $conversationIds->count() === 1 ? (string) $conversationIds->first() : null);

        return $resolved;
    }
}
