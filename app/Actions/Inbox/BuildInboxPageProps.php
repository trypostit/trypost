<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\PostsFilter;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Http\Resources\Api\InboxConversationResource;
use App\Http\Resources\Api\InboxMessageResource;
use App\Http\Resources\Api\InboxScoreResource;
use App\Http\Resources\App\InboxSavedRepliesResource;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;

class BuildInboxPageProps
{
    /**
     * Networks shown as "Coming soon" on the Mentions tab.
     *
     * @var list<Platform>
     */
    public const array MENTIONS_COMING_SOON = [Platform::X];

    public const string DIRECT_MESSAGES_PAGE_NAME = 'dm_page';

    /**
     * @return array<string, mixed>
     */
    public static function execute(User $user, Workspace $workspace, InboxFilters $filters, string $view, ?InboxConversation $selected = null, ?string $threadAround = null, ?string $messagesAround = null): array
    {
        $isMentions = $filters->tab === InboxTab::Mentions;
        $view = match ($filters->tab) {
            InboxTab::Mentions => 'list',
            InboxTab::Messages => 'messages',
            default => $view,
        };
        $isPostView = $view === 'post';
        $isMessagesView = $view === 'messages';
        $listsConversations = $isPostView || $isMessagesView;
        $kinds = $isMessagesView ? null : [ConversationKind::Comment];
        $perPage = (int) config('app.pagination.default');
        $isOpened = filled($selected);
        $listsAllPosts = $isPostView && $filters->listsAllPosts();
        $canReply = $user->canPublishDirectlyIn($workspace);
        $opened = function () use ($listsConversations, &$selected, $workspace, $filters, $kinds): ?InboxConversation {
            if (! $listsConversations) {
                return null;
            }

            return $selected ??= ListInboxConversations::first($workspace, $filters, $kinds);
        };

        return [
            'view' => $view,
            'filters' => [
                'channels' => $filters->scopedChannelId ? [] : $filters->channelIds,
                'channel' => $filters->scopedChannelId,
                'status' => $filters->status->value,
                'sort' => $filters->sort->value,
                'range' => $filters->range->value,
                'tab' => $filters->tab->value,
                'posts' => $listsAllPosts ? PostsFilter::All->value : PostsFilter::Commented->value,
            ],
            'conversations' => $listsConversations
                ? Inertia::scroll(fn () => InboxConversationResource::collection(
                    ListInboxConversations::paginate($workspace, $filters, $kinds)
                ))->matchOn($listsAllPosts ? 'data.post_id' : 'data.id')
                : null,
            'conversation' => function () use ($opened): ?array {
                $conversation = $opened();

                return filled($conversation)
                    ? InboxConversationResource::make(ShowInboxConversation::execute($conversation))->resolve()
                    : null;
            },
            'conversationThreads' => $isPostView
                ? Inertia::scroll(function () use ($opened, $perPage, $threadAround) {
                    $conversation = $opened()?->exists ? $opened() : null;
                    $page = filled($conversation) && filled($threadAround)
                        ? ListConversationThreads::pageOf($conversation, $threadAround, $perPage)
                        : null;

                    return InboxMessageResource::collection(filled($conversation)
                        ? ListConversationThreads::execute($conversation)->paginate($perPage, pageName: ListConversationThreads::PAGE_NAME, page: $page)
                        : InboxMessage::query()->whereKey([])->paginate($perPage, pageName: ListConversationThreads::PAGE_NAME));
                })->matchOn('data.id')
                : null,
            'directMessages' => $isMessagesView
                ? Inertia::scroll(function () use ($opened, $perPage) {
                    $conversation = $opened();

                    return InboxMessageResource::collection(InboxMessage::query()
                        ->where('inbox_conversation_id', $conversation?->id)
                        ->with('sentBy')
                        ->orderByDesc('platform_created_at')
                        ->orderByDesc('id')
                        ->paginate($perPage, pageName: self::DIRECT_MESSAGES_PAGE_NAME));
                })->matchOn('data.id')
                : null,
            'isConversationOpened' => $isOpened,
            'messages' => $listsConversations
                ? null
                : Inertia::scroll(fn () => InboxMessageResource::collection(
                    ListInboxMessages::execute($workspace, $filters)->paginate($perPage, page: filled($messagesAround) ? ListInboxMessages::pageOf($workspace, $filters, $messagesAround, $perPage) : null)
                ))->matchOn('data.id'),
            'repliedRecentlyCount' => $listsConversations || $isMentions ? null : fn (): int => self::repliedRecentlyCount($workspace, $filters->channelIds),
            'tabCounts' => fn (): array => [
                'comments' => CountOpenInboxMessages::execute($workspace, InboxTab::Comments, $filters->channelIds, $filters->range)['total'],
                'mentions' => self::mentionsOn() && self::scopeHas($filters, ConversationKind::Mention) ? CountOpenInboxMessages::execute($workspace, InboxTab::Mentions, $filters->channelIds, $filters->range)['total'] : null,
                'messages' => self::directMessagesOn($workspace, $filters) ? CountOpenInboxMessages::execute($workspace, InboxTab::Messages, $filters->channelIds, $filters->range)['total'] : null,
            ],
            'commentScore' => Inertia::defer(fn (): array => InboxScoreResource::make(CalculateInboxScore::cached($workspace, $user, $filters->channelIds))->resolve()),
            'mentionsAvailability' => fn (): array => self::mentionsAvailability(),
            'inboxChannels' => fn (): array => self::channels($workspace, $filters->tab, $filters->range, $filters->scopedChannelId),
            'filterChannels' => fn (): array => self::filterChannels($workspace),
            'hasInboxChannels' => fn (): bool => $workspace->socialAccounts()->whereIn('platform', Platform::inboxValues())->exists(),
            'hasConversations' => fn (): bool => InboxConversation::query()
                ->visibleInTab($workspace, $filters->tab)
                ->when($filters->scopedChannelId, fn (Builder $query, string $channelId) => $query->where('social_account_id', $channelId))
                ->exists(),
            'showsReorderHint' => blank($user->inbox_reorder_hint_seen_at),
            'backfillDays' => (int) config('trypost.inbox.backfill_days'),
            'canReply' => $canReply,
            'savedReplies' => $canReply ? InboxSavedRepliesResource::once($workspace) : [],
        ];
    }

    /**
     * @param  list<string>  $channelIds
     */
    private static function repliedRecentlyCount(Workspace $workspace, array $channelIds): int
    {
        return InboxMessage::query()
            ->where('direction', MessageDirection::Inbound->value)
            ->where('answered_at', '>=', now()->subDays(7))
            ->whereHas('conversation', fn (Builder $conversations) => $conversations
                ->visibleInTab($workspace, InboxTab::Comments)
                ->when(filled($channelIds), fn (Builder $query) => $query->whereIn('social_account_id', $channelIds)))
            ->count();
    }

    /**
     * The Messages tab shows once a channel of the workspace can have direct
     * messages: its driver reads them.
     */
    public static function directMessagesOn(Workspace $workspace, ?InboxFilters $filters = null): bool
    {
        $platforms = array_values(array_filter(Platform::inboxValues(), fn (string $value): bool => Platform::from($value)->inboxKindEnabled(ConversationKind::DirectMessage)));

        return $platforms !== []
            && $workspace->socialAccounts()->whereIn('platform', $platforms)->exists()
            && ($filters === null || self::scopeHas($filters, ConversationKind::DirectMessage));
    }

    /**
     * Whether the page's channel syncs a kind: a channel's own inbox shows
     * only the tabs its channel has. The workspace inbox has every tab.
     */
    private static function scopeHas(InboxFilters $filters, ConversationKind $kind): bool
    {
        if (blank($filters->scopedChannelId)) {
            return true;
        }

        $account = SocialAccount::query()->find($filters->scopedChannelId);

        return $account !== null && in_array($kind, app(InboxDrivers::class)->kindsFor($account), true);
    }

    private static function mentionsOn(): bool
    {
        return collect(Platform::cases())->contains(fn (Platform $platform): bool => $platform->inboxKindEnabled(ConversationKind::Mention));
    }

    /**
     * @return list<array{platform: string, state: string}>
     */
    private static function mentionsAvailability(): array
    {
        if (! self::mentionsOn()) {
            return [];
        }

        return collect(Platform::cases())
            ->filter(fn (Platform $platform): bool => $platform->inboxKindEnabled(ConversationKind::Mention))
            ->map(fn (Platform $platform): string => $platform->network())
            ->unique()
            ->map(fn (string $network): array => ['platform' => $network, 'state' => 'available'])
            ->concat(collect(self::MENTIONS_COMING_SOON)->map(fn (Platform $platform): array => ['platform' => $platform->value, 'state' => 'coming_soon']))
            ->values()
            ->all();
    }

    /**
     * @return array{max_message_length: int, message_length_unit: string|null}
     */
    private static function messageLimit(InboxDrivers $drivers, SocialAccount $account): array
    {
        $driver = $drivers->for($account->platform);
        $limit = $driver instanceof DirectMessageDriver && $account->platform->inboxKindEnabled(ConversationKind::DirectMessage) ? $driver->directMessageLimit($account) : null;

        return [
            'max_message_length' => $limit?->max ?? 0,
            'message_length_unit' => $limit?->unit->value,
        ];
    }

    /**
     * How each kind the channel syncs is doing: why it stopped, if it did
     * (messages_unavailable, permission or failing), and when it last synced.
     *
     * @return list<array{kind: string, sync_error: ?string, last_synced_at: ?string}>
     */
    private static function syncStates(InboxDrivers $drivers, SocialAccount $account): array
    {
        $states = $account->inboxSyncStates->keyBy(fn (InboxSyncState $state): string => $state->kind->value);

        return array_map(fn (ConversationKind $kind): array => [
            'kind' => $kind->value,
            'sync_error' => $states->get($kind->value)?->syncError()?->value,
            'last_synced_at' => $states->get($kind->value)?->last_synced_at?->toIso8601String(),
        ], $drivers->kindsFor($account));
    }

    /**
     * Every channel of the workspace for the channel filter, in the
     * workspace's order; the ones without an inbox are listed but cannot be
     * picked.
     *
     * @return list<array{id: string, platform: string, username: string, display_name: ?string, avatar_url: ?string, status: string, has_inbox: bool}>
     */
    private static function filterChannels(Workspace $workspace): array
    {
        $inboxPlatforms = Platform::inboxValues();

        return $workspace->socialAccounts()
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'username' => (string) $account->username,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status->value,
                'has_inbox' => in_array($account->platform->value, $inboxPlatforms, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function channels(Workspace $workspace, InboxTab $tab, DateRange $range, ?string $scopedChannelId = null): array
    {
        $counts = CountOpenInboxMessages::execute($workspace, $tab, range: $range)['channels'];
        $drivers = app(InboxDrivers::class);

        return $workspace->socialAccounts()
            ->whereIn('platform', Platform::inboxValues())
            ->when($scopedChannelId, fn (Builder $query, string $channelId) => $query->whereKey($channelId))
            ->with('inboxSyncStates')
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'username' => (string) $account->username,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'has_inbox_access' => $account->hasInboxAccess(),
                'supports_mentions' => $account->platform->inboxKindEnabled(ConversationKind::Mention),
                'has_mention_access' => $account->hasInboxMentionAccess(),
                'is_connected' => $account->status === Status::Connected,
                'open_count' => (int) data_get($counts, $account->id, 0),
                'max_reply_length' => (int) $drivers->for($account->platform)?->replyLength($account),
                'prefills_mention' => (bool) $drivers->for($account->platform)?->prefillsMention(),
                'kinds' => array_map(fn (ConversationKind $kind): string => $kind->value, $drivers->kindsFor($account)),
                'reply_length_unit' => ($drivers->for($account->platform)?->replyLengthUnit() ?? ReplyLengthUnit::Characters)->value,
                'supports_direct_messages' => $account->platform->inboxKindEnabled(ConversationKind::DirectMessage),
                'has_direct_message_access' => $account->hasInboxAccessFor(ConversationKind::DirectMessage),
                'sync_states' => self::syncStates($drivers, $account),
                ...self::messageLimit($drivers, $account),
            ])
            ->values()
            ->all();
    }
}
