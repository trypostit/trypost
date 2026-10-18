<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReviewReplyState;
use App\Events\InboxUpdated;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxPendingMessage;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RemoveInboxMessages
{
    /** @param list<string> $platformMessageIds */
    public static function execute(SocialAccount $account, array $platformMessageIds): int
    {
        if ($platformMessageIds === []) {
            return 0;
        }

        $hashes = array_map(InboxConversation::hashOf(...), array_values(array_unique($platformMessageIds)));
        $targets = InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->whereIn('platform_message_hash', $hashes)
            ->get(['id', 'root_id', 'inbox_conversation_id', 'direction', 'platform_message_hash']);

        InboxPendingMessage::query()->where('social_account_id', $account->id)->whereIn('platform_message_hash', $hashes)->delete();

        collect($hashes)
            ->diff($targets->pluck('platform_message_hash'))
            ->each(fn (string $hash) => Cache::put(self::tombstoneKey($account, $hash), true, now()->addDays((int) config('trypost.inbox.backfill_days'))));

        if ($targets->isEmpty()) {
            return 0;
        }

        $roots = $targets->whereNull('root_id')->pluck('id');
        $conversationIds = $targets->pluck('inbox_conversation_id')->unique()->values();

        $changed = DB::transaction(function () use ($account, $targets, $roots, $conversationIds): int {
            $changed = InboxMessage::query()
                ->where('social_account_id', $account->id)
                ->whereNull('removed_at')
                ->where(fn (Builder $tree) => $tree->whereIn('id', $targets->pluck('id'))->orWhereIn('root_id', $roots))
                ->update(['removed_at' => now()]);

            if ($changed > 0) {
                self::reopenUnansweredTrees($targets);

                InboxConversation::query()
                    ->whereIn('id', $conversationIds)
                    ->get()
                    ->each(fn (InboxConversation $conversation) => RecountInboxConversation::execute($conversation));
            }

            return $changed;
        });

        if ($changed > 0) {
            $conversationIds->each(fn (string $conversationId) => InboxUpdated::dispatch($account->workspace_id, $conversationId));
        }

        return $changed;
    }

    /**
     * Whether a removal arrived before the message itself (Meta does not keep
     * webhook order): the message is then stored as removed.
     */
    public static function wasRemoved(SocialAccount $account, string $platformMessageId): bool
    {
        return Cache::has(self::tombstoneKey($account, InboxConversation::hashOf($platformMessageId)));
    }

    private static function tombstoneKey(SocialAccount $account, string $hash): string
    {
        return "inbox:removed:{$account->id}:{$hash}";
    }

    /**
     * A removed reply no longer answers anything: in its tree (the whole
     * conversation when it is flat, a DM) the inbound messages written after
     * the newest reply still standing reopen. A reply still sending, one that
     * failed or a rejected review reply never answered anything.
     *
     * @param  Collection<int, InboxMessage>  $targets
     */
    private static function reopenUnansweredTrees(Collection $targets): void
    {
        $outbound = $targets->where('direction', MessageDirection::Outbound);
        $flat = InboxConversation::query()
            ->whereIn('id', $outbound->pluck('inbox_conversation_id')->unique())
            ->where('kind', ConversationKind::DirectMessage->value)
            ->pluck('id')
            ->all();

        $outbound
            ->map(fn (InboxMessage $reply): ?array => match (true) {
                in_array($reply->inbox_conversation_id, $flat, true) => ['conversation', $reply->inbox_conversation_id],
                filled($reply->root_id) => ['tree', $reply->root_id],
                default => null,
            })
            ->filter()
            ->unique(fn (array $scope): string => implode(':', $scope))
            ->each(function (array $scope): void {
                [$type, $id] = $scope;
                $within = fn (Builder $query) => $type === 'conversation'
                    ? $query->where('inbox_conversation_id', $id)
                    : $query->where(fn (Builder $tree) => $tree->where('id', $id)->orWhere('root_id', $id));

                $lastReplyAt = InboxMessage::query()
                    ->where($within)
                    ->where('direction', MessageDirection::Outbound->value)
                    ->whereNull('removed_at')
                    ->where(fn (Builder $delivery) => $delivery->whereNull('delivery_status')->orWhere('delivery_status', DeliveryStatus::Sent->value))
                    ->where(fn (Builder $state) => $state->whereNull('review_reply_state')->orWhere('review_reply_state', '!=', ReviewReplyState::Rejected->value))
                    ->max('platform_created_at');

                InboxMessage::query()
                    ->where($within)
                    ->where('direction', MessageDirection::Inbound->value)
                    ->whereNotNull('answered_at')
                    ->when(filled($lastReplyAt), fn (Builder $query) => $query->where('platform_created_at', '>', $lastReplyAt))
                    ->update(['answered_at' => null]);
            });
    }
}
