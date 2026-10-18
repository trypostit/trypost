<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\IncomingMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReviewReplyState;
use App\Events\InboxUpdated;
use App\Models\AnalyticsPublication;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxMediaSlots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UpsertInboxMessage
{
    /**
     * A webhook and a poll may insert the same first message at once. On
     * MySQL the losing transaction cannot see the winner's row from its
     * snapshot, so the whole upsert runs again once, in a new transaction.
     */
    public static function execute(SocialAccount $account, IncomingMessage $incoming, bool $broadcast = true): InboxMessage
    {
        try {
            $message = self::store($account, $incoming);
        } catch (UniqueConstraintViolationException) {
            $message = self::store($account, $incoming);
        }

        StoreInboxMedia::dispatchFor($message);

        if ($broadcast) {
            collect([$message->inbox_conversation_id, ...$message->leftConversationIds])
                ->each(fn (string $conversationId) => InboxUpdated::dispatch($account->workspace_id, $conversationId));
        }

        return $message;
    }

    private static function store(SocialAccount $account, IncomingMessage $incoming): InboxMessage
    {
        return DB::transaction(function () use ($account, $incoming): InboxMessage {
            $identity = [
                'social_account_id' => $account->id,
                'platform_message_hash' => InboxConversation::hashOf($incoming->platformMessageId),
            ];
            $existing = InboxMessage::query()->where($identity)->first();
            $parent = self::parent($account, $incoming)
                ?? (filled($existing?->parent_id) ? InboxMessage::query()->with('conversation')->find($existing->parent_id) : null);
            $conversation = $parent?->conversation ?? self::conversation($account, $incoming);
            $previousConversationId = $existing?->inbox_conversation_id;
            $isOwn = $incoming->direction === MessageDirection::Outbound || filled($existing?->sent_by_user_id);
            $removed = $incoming->removed || RemoveInboxMessages::wasRemoved($account, $incoming->platformMessageId);
            $values = InboxMediaSlots::fromNetwork([
                'inbox_conversation_id' => $conversation->id,
                'platform_message_id' => $incoming->platformMessageId,
                'platform_parent_id' => $incoming->platformParentId,
                'platform_parent_hash' => filled($incoming->platformParentId) ? InboxConversation::hashOf($incoming->platformParentId) : null,
                'parent_id' => $parent?->id,
                'root_id' => $parent ? ($parent->root_id ?? $parent->id) : null,
                'direction' => $incoming->direction,
                'author_platform_id' => $incoming->authorPlatformId,
                'author_name' => $incoming->authorName,
                'author_username' => $incoming->authorUsername,
                'author_avatar_url' => $isOwn || $incoming->kind === ConversationKind::DirectMessage ? null : $incoming->authorAvatarUrl,
                'body' => $incoming->body,
                'media' => $incoming->media,
                'meta' => $incoming->meta,
                'platform_url' => $incoming->platformUrl,
                'platform_created_at' => $incoming->platformCreatedAt,
                'observed_at' => $incoming->observedAt ?? now(),
                'review_reply_state' => $incoming->reviewReplyState,
            ]);

            $message = $existing ?? InboxMessage::query()->createOrFirst($identity, $values);

            if (! $message->wasRecentlyCreated) {
                $message->fill(self::keepingKnownValues($message, $incoming, $values))->save();
            }

            if ($removed && blank($message->removed_at)) {
                $message->forceFill(['removed_at' => now()])->save();
            } elseif (! $removed && filled($message->removed_at) && self::isReviewReply($incoming)) {
                $message->forceFill(['removed_at' => null])->save();
            }

            if ($incoming->hidden !== null && $incoming->hidden !== filled($message->hidden_at)) {
                $message->forceFill(['hidden_at' => $incoming->hidden ? now() : null])->save();
            }

            $leftConversationIds = self::adoptOrphans($message);

            self::markTreeAnswered($message, $conversation);

            RecountInboxConversation::execute($conversation);

            $message->leftConversationIds = InboxConversation::query()
                ->whereIn('id', array_values(array_diff(array_filter([$previousConversationId, ...$leftConversationIds]), [$message->inbox_conversation_id])))
                ->orderBy('id')
                ->get()
                ->each(fn (InboxConversation $previous) => RecountInboxConversation::execute($previous))
                ->pluck('id')
                ->all();

            return $message->setRelation('conversation', $conversation);
        });
    }

    /**
     * A later, partial delivery of a stored message (a webhook without the
     * author, the link or the real time) never blanks what is already known,
     * the first time stored stays, and a reply sent from the inbox stays outbound.
     * A review reply is the exception to the frozen time: Google keeps one
     * reply per review under the same id, so a replaced reply brings its new
     * time. The review reply state always comes from the latest read, so a
     * rejection replaces pending. A DM keeps its media and content when a
     * later read carries none.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function keepingKnownValues(InboxMessage $message, IncomingMessage $incoming, array $values): array
    {
        if (! $message->exists) {
            return $values;
        }

        foreach (['body', 'platform_url', 'author_platform_id', 'author_name', 'author_username', 'author_avatar_path'] as $key) {
            if (blank(data_get($values, $key)) && filled($message->getAttribute($key))) {
                unset($values[$key]);
            }
        }

        if (blank(data_get($values, 'platform_parent_id')) && filled($message->platform_parent_id)) {
            unset($values['platform_parent_id'], $values['platform_parent_hash']);
        }

        if (filled($message->platform_created_at) && ! self::isReviewReply($incoming)) {
            unset($values['platform_created_at']);
        }

        if (filled($message->sent_by_user_id)) {
            unset($values['direction']);
        }

        if ($incoming->observedAt === null) {
            unset($values['observed_at']);
        }

        if (self::isStale($message, $incoming)) {
            foreach (['body', 'media', 'meta', 'platform_url', 'author_platform_id', 'author_name', 'author_username', 'author_avatar_path', 'review_reply_state', 'observed_at'] as $key) {
                if (filled($message->getAttribute($key))) {
                    unset($values[$key]);
                }
            }

            return self::withoutEquivalentJson($message, InboxMediaSlots::keepStored($message, $values), ['media', 'meta']);
        }

        if ($incoming->kind === ConversationKind::DirectMessage) {
            $values['media'] = data_get($values, 'media') === [] ? ($message->media ?? []) : data_get($values, 'media');
            $values['meta'] = self::directMessageMeta((array) $message->meta, (array) data_get($values, 'meta', []));
        }

        return self::withoutEquivalentJson($message, InboxMediaSlots::keepStored($message, $values), ['media', 'meta']);
    }

    /**
     * MySQL stores JSON objects with their keys reordered, so a value read
     * back equal to the stored one but in another key order is not a change:
     * it is dropped instead of saved again and broadcast.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function withoutEquivalentJson(InboxMessage|InboxConversation $model, array $values, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && self::sameJson($model->getAttribute($key), data_get($values, $key))) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    private static function sameJson(mixed $stored, mixed $incoming): bool
    {
        return self::sortedKeys($stored) === self::sortedKeys($incoming);
    }

    private static function sortedKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => self::sortedKeys($item), $value);
    }

    /**
     * A DM read again fills what an earlier read left empty without losing
     * what only the earlier read had: a webhook brings a shared post's
     * preview, the Conversations API its link. Reactions come from the
     * latest read that lists them.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private static function directMessageMeta(array $stored, array $incoming): array
    {
        $storedContent = (array) data_get($stored, 'content', []);
        $incomingContent = (array) data_get($incoming, 'content', []);

        $content = match (true) {
            $incomingContent === [] => $storedContent,
            data_get($storedContent, 'type') === data_get($incomingContent, 'type') => [...$storedContent, ...$incomingContent],
            default => $incomingContent,
        };

        return array_filter([
            ...$stored,
            ...$incoming,
            'content' => $content,
            'reactions' => data_get($incoming, 'reactions') ?? data_get($stored, 'reactions'),
        ], fn (mixed $value): bool => filled($value));
    }

    /**
     * A delivery older than what is stored (Meta does not keep webhook order,
     * and retries an event for up to 36 hours) never overwrites a newer
     * state: it only fills what is still empty.
     */
    private static function isStale(InboxMessage $message, IncomingMessage $incoming): bool
    {
        return $incoming->observedAt !== null
            && filled($message->observed_at)
            && $incoming->observedAt->lt($message->observed_at);
    }

    private static function isReviewReply(IncomingMessage $incoming): bool
    {
        return $incoming->kind === ConversationKind::Review && filled($incoming->platformParentId);
    }

    private static function conversation(SocialAccount $account, IncomingMessage $incoming): InboxConversation
    {
        $subject = $incoming->subject;

        $conversation = InboxConversation::query()->firstOrCreate(
            [
                'social_account_id' => $account->id,
                'kind' => $incoming->kind,
                'platform_conversation_hash' => InboxConversation::hashOf($subject->platformId),
            ],
            fn (): array => InboxMediaSlots::fromNetwork([
                'workspace_id' => $account->workspace_id,
                'platform_conversation_id' => $subject->platformId,
                'analytics_publication_id' => self::publicationId($account, $incoming),
                'post_id' => LinkInboxConversationPost::forSubject($account, $incoming),
                ...self::subjectSnapshot($incoming),
                'rating' => $incoming->rating,
                'participant_name' => $subject->participantName,
                'participant_username' => $subject->participantUsername,
                'participant_avatar_url' => $subject->participantAvatarUrl,
                'meta' => $subject->meta,
            ]),
        );

        if ($conversation->wasRecentlyCreated) {
            return $conversation;
        }

        $conversation->fill(InboxMediaSlots::keepStored($conversation, InboxMediaSlots::fromNetwork([...array_filter([
            ...self::subjectSnapshot($incoming),
            'subject_media' => $incoming->kind === ConversationKind::Comment || $subject->media === [] ? null : $subject->media,
            'post_id' => blank($conversation->post_id) ? LinkInboxConversationPost::forSubject($account, $incoming) : null,
            'rating' => $incoming->rating,
            'participant_name' => $subject->participantName,
            'participant_username' => $subject->participantUsername,
            'participant_avatar_url' => $subject->participantAvatarUrl,
            'analytics_publication_id' => blank($conversation->analytics_publication_id) ? self::publicationId($account, $incoming) : null,
        ], fn (mixed $value): bool => filled($value)), 'meta' => $conversation->meta ?? []])));

        foreach (['subject_media', 'meta'] as $key) {
            if (self::sameJson($conversation->getOriginal($key), $conversation->getAttribute($key))) {
                $conversation->syncOriginalAttribute($key);
            }
        }

        if ($conversation->isDirty()) {
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * A comment's post is in TryPost, so its conversation keeps no copy of
     * it; a mention is about a post of someone else, kept as read.
     *
     * @return array<string, mixed>
     */
    private static function subjectSnapshot(IncomingMessage $incoming): array
    {
        if ($incoming->kind === ConversationKind::Comment) {
            return [];
        }

        return [
            'subject_text' => $incoming->subject->text,
            'subject_media' => $incoming->subject->media,
            'subject_url' => $incoming->subject->url,
            'subject_published_at' => $incoming->subject->publishedAt,
        ];
    }

    private static function publicationId(SocialAccount $account, IncomingMessage $incoming): ?string
    {
        $remoteId = $incoming->subject->remoteId;

        return filled($remoteId)
            ? AnalyticsPublication::query()->where('social_account_id', $account->id)->where('remote_id', $remoteId)->value('id')
            : null;
    }

    private static function parent(SocialAccount $account, IncomingMessage $incoming): ?InboxMessage
    {
        if (blank($incoming->platformParentId)) {
            return null;
        }

        return InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->where('platform_message_hash', InboxConversation::hashOf($incoming->platformParentId))
            ->with('conversation')
            ->first();
    }

    /**
     * Hangs the replies that arrived before this message under it and moves
     * its whole tree into its conversation.
     *
     * @return list<string> the conversations the tree left
     */
    private static function adoptOrphans(InboxMessage $message): array
    {
        $left = [];
        $rootId = $message->root_id ?? $message->id;

        $frontier = InboxMessage::query()
            ->where('social_account_id', $message->social_account_id)
            ->where('platform_parent_hash', $message->platform_message_hash)
            ->whereNull('parent_id')
            ->pluck('id');

        InboxMessage::query()->whereIn('id', $frontier)->update(['parent_id' => $message->id]);

        $frontier = InboxMessage::query()->where('parent_id', $message->id)->pluck('id');
        $seen = [$message->id];

        while ($frontier->isNotEmpty()) {
            $left = [...$left, ...InboxMessage::query()->whereIn('id', $frontier)->where('inbox_conversation_id', '!=', $message->inbox_conversation_id)->distinct()->pluck('inbox_conversation_id')->all()];
            InboxMessage::query()->whereIn('id', $frontier)->update(['root_id' => $rootId, 'inbox_conversation_id' => $message->inbox_conversation_id]);

            $seen = [...$seen, ...$frontier->all()];
            $frontier = InboxMessage::query()->whereIn('parent_id', $frontier)->whereNotIn('id', $seen)->pluck('id');
        }

        return array_values(array_unique($left));
    }

    private static function markTreeAnswered(InboxMessage $message, InboxConversation $conversation): void
    {
        $rootId = $message->root_id ?? $message->id;
        $tree = fn (Builder $query) => $conversation->isFlat() ? $query : $query->where('id', $rootId)->orWhere('root_id', $rootId);

        $latestOutbound = InboxMessage::query()
            ->where('inbox_conversation_id', $message->inbox_conversation_id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where($tree)
            ->whereNull('removed_at')
            ->where(fn (Builder $delivered) => $delivered->whereNull('delivery_status')->orWhere('delivery_status', DeliveryStatus::Sent->value))
            ->where(fn (Builder $state) => $state->whereNull('review_reply_state')->orWhere('review_reply_state', '!=', ReviewReplyState::Rejected->value))
            ->orderByDesc('platform_created_at')
            ->first();

        if ($latestOutbound) {
            MarkInboxTreeAnswered::execute($latestOutbound);

            return;
        }

        if ($message->review_reply_state === ReviewReplyState::Rejected) {
            InboxMessage::query()
                ->where('inbox_conversation_id', $message->inbox_conversation_id)
                ->where('direction', MessageDirection::Inbound->value)
                ->where($tree)
                ->update(['answered_at' => null]);
        }
    }
}
