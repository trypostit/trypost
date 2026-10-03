<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Actions\Post\Queue\BuildQueueTimeline;
use App\Actions\SocialAccount\CountPostsScheduledThisWeek;
use App\Actions\SocialAccount\CountPostsSentThisWeek;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\User\WeekStart;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostStatusRules;
use App\Support\RequestIds;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BuildPublishPageProps
{
    public const TAB_QUEUE = 'queue';

    public const TAB_DRAFTS = 'drafts';

    public const TAB_SENT = 'sent';

    public const TAB_APPROVALS = 'approvals';

    public const MIN_QUEUE_DAYS = 14;

    public const MAX_QUEUE_DAYS = 90;

    public const SENT_STATUSES = [
        PostStatus::Published,
        PostStatus::PartiallyPublished,
        PostStatus::Failed,
        PostStatus::Publishing,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function handle(Request $request, Workspace $workspace, ?SocialAccount $channel, ?Post $editPost = null): array
    {
        $user = $request->user();
        $requester = self::pendingApprovalsRequester($user, $workspace);
        $userTimezone = Timezone::normalize($user->timezone);
        $displayTimezone = self::displayTimezone($request->query('tz'), $userTimezone);

        $labelIds = RequestIds::uuidList($request->collect('labels'));
        $untagged = $request->boolean('untagged');
        $requestedChannelIds = $channel ? [] : RequestIds::uuidList($request->collect('channels')->unique());

        $workspaceAccounts = null;
        $filterAccounts = function () use (&$workspaceAccounts, $channel, $workspace): Collection {
            return $workspaceAccounts ??= $channel ? collect() : $workspace->socialAccounts()->get();
        };

        $channels = fn (): Collection => match (true) {
            $channel !== null => collect([$channel]),
            $requestedChannelIds !== [] => $filterAccounts()->whereIn('id', $requestedChannelIds)->values(),
            default => $filterAccounts(),
        };

        $scopedChannelIds = $channel ? [$channel->id] : ($requestedChannelIds !== [] ? $requestedChannelIds : null);

        $basePosts = $workspace->posts()->when($scopedChannelIds !== null, fn (Builder $query) => $query->whereHas(
            'postPlatforms',
            fn (Builder $platforms) => $platforms->enabled()->whereIn('social_account_id', $scopedChannelIds),
        ));

        $openPostNotesId = self::uuidQuery($request, 'notes');
        $openPostDetailsId = self::uuidQuery($request, 'post');
        $focusedPostId = $openPostNotesId ?? $openPostDetailsId;

        $tab = self::tab($request->query('tab'), $focusedPostId ? (clone $basePosts)->whereKey($focusedPostId)->value('status') : null);

        $cards = fn (): Builder => self::cardQuery(clone $basePosts, $scopedChannelIds, $labelIds, $untagged);

        $props = [
            'workspace' => $workspace,
            'scope' => $channel ? 'channel' : 'all',
            'channel' => fn (): ?array => $channel ? self::channelHeader($channel, $user->week_starts_on) : null,
            'tab' => $tab,
            'counts' => fn (): array => [
                ...self::counts(clone $basePosts),
                'approvals' => (clone $basePosts)->pendingApproval()->visiblePendingApprovalsFor($requester)->count(),
            ],
            'displayTimezone' => $displayTimezone,
            'timezones' => fn (): array => Timezone::options(),
            'labels' => fn () => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'filters' => [
                'labels' => $labelIds,
                'untagged' => $untagged,
                'channels' => $requestedChannelIds,
            ],
            'filterAccounts' => fn () => SocialAccountResource::collection($filterAccounts()),
        ];

        if ($tab === self::TAB_QUEUE) {
            $props['queue'] = fn (): array => self::queue($workspace, $channels(), $displayTimezone, $request, $labelIds, $untagged, $cards, $channel !== null, $requester);
        }

        $props['posts'] = Inertia::scroll(fn () => self::paginatedCards($tab, $cards(), $tab === self::TAB_QUEUE ? null : $focusedPostId, $requester));

        $composerRequested = $editPost !== null;

        return [
            ...$props,
            'openComposer' => $composerRequested,
            'openComposerAssistant' => $request->boolean('assistant'),
            'initialComposerDate' => $request->query('date'),
            'openPostNotesId' => $openPostNotesId,
            'openPostDetailsId' => $openPostDetailsId,
            'highlightNoteId' => is_string($request->query('note')) ? $request->query('note') : null,
            'authUserId' => $user->id,
            'editPost' => $editPost,
            ...BuildComposerProps::lazy($workspace, $composerRequested),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function channelHeader(SocialAccount $channel, WeekStart $weekStart): array
    {
        return [
            ...SocialAccountResource::make($channel)->resolve(),
            'posting_goal' => $channel->posting_goal,
            'sent_this_week' => CountPostsSentThisWeek::handle($channel, $weekStart),
            'scheduled_this_week' => CountPostsScheduledThisWeek::handle($channel, $weekStart),
            'has_grid' => $channel->platform->hasProfileGrid(),
        ];
    }

    public static function displayTimezone(mixed $requested, string $userTimezone): string
    {
        if (! is_string($requested) || $requested === '') {
            return $userTimezone;
        }

        $normalized = Timezone::normalize($requested);

        return $normalized === Timezone::DEFAULT && $requested !== Timezone::DEFAULT ? $userTimezone : $normalized;
    }

    private static function uuidQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private static function tab(mixed $requested, ?PostStatus $focusedPostStatus): string
    {
        if (blank($requested) && $focusedPostStatus !== null) {
            return match (true) {
                $focusedPostStatus === PostStatus::PendingApproval => self::TAB_APPROVALS,
                $focusedPostStatus === PostStatus::Draft => self::TAB_DRAFTS,
                in_array($focusedPostStatus, self::SENT_STATUSES, true) => self::TAB_SENT,
                default => self::TAB_QUEUE,
            };
        }

        return match ($requested) {
            self::TAB_DRAFTS => self::TAB_DRAFTS,
            self::TAB_SENT => self::TAB_SENT,
            self::TAB_APPROVALS => self::TAB_APPROVALS,
            default => self::TAB_QUEUE,
        };
    }

    /**
     * @return array{queue: int, drafts: int, sent: int}
     */
    private static function counts(HasMany $basePosts): array
    {
        $byStatus = $basePosts
            ->toBase()
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count);

        return [
            'queue' => $byStatus->get(PostStatus::Scheduled->value, 0),
            'drafts' => $byStatus->get(PostStatus::Draft->value, 0),
            'sent' => collect(self::SENT_STATUSES)->sum(fn (PostStatus $status): int => $byStatus->get($status->value, 0)),
        ];
    }

    /**
     * The user whose own requests are the only pending posts they see; null for
     * an approver, who sees every one (see Post::scopeVisiblePendingApprovalsFor()).
     */
    public static function pendingApprovalsRequester(User $user, Workspace $workspace): ?User
    {
        return $user->can('approvePosts', $workspace) ? null : $user;
    }

    /**
     * @param  list<string>|null  $channelIds
     * @param  list<string>  $labelIds
     */
    public static function cardQuery(HasMany $basePosts, ?array $channelIds, array $labelIds, bool $untagged): Builder
    {
        return $basePosts->getQuery()
            ->with([
                'postPlatforms' => fn ($platforms) => $platforms->enabled()
                    ->when($channelIds !== null, fn ($platforms) => $platforms->whereIn('social_account_id', $channelIds))
                    ->with('socialAccount'),
                'user.avatarMedia',
                'labels',
            ])
            ->withCount(['notes', 'groupPosts'])
            ->matchingLabelFilter($labelIds, $untagged);
    }

    /**
     * A channel page always gets its timeline (scheduled posts and free posting times);
     * the all-channels page only gets one while nothing is scheduled. Pending queue
     * requests holding a slot come along for the viewer who may see them.
     *
     * @param  Collection<int, SocialAccount>  $channels
     * @param  list<string>  $labelIds
     * @param  callable(): Builder  $cards
     * @return array{days: list<array<string, mixed>>, pending: list<Post>, queueDays: int, maxQueueDays: int}
     */
    private static function queue(Workspace $workspace, Collection $channels, string $displayTimezone, Request $request, array $labelIds, bool $untagged, callable $cards, bool $channelScope, ?User $requester): array
    {
        $queueDays = max(self::MIN_QUEUE_DAYS, min(self::MAX_QUEUE_DAYS, $request->integer('queue_days', self::MIN_QUEUE_DAYS)));

        $days = ! $channelScope && $cards()->where('status', PostStatus::Scheduled)->exists()
            ? []
            : BuildQueueTimeline::handle($workspace, $channels, $displayTimezone, now()->addDays($queueDays), $labelIds, $untagged);

        $pending = $cards()
            ->pendingApproval()
            ->where('posts.schedule_mode', ScheduleMode::Queue)
            ->where('posts.scheduled_at', '>', now())
            ->visiblePendingApprovalsFor($requester)
            ->orderBy('posts.scheduled_at')
            ->orderBy('posts.id')
            ->limit((int) config('app.pagination.default'))
            ->get();

        self::decorate($pending);

        return [
            'days' => $days,
            'pending' => $pending->values()->all(),
            'queueDays' => $queueDays,
            'maxQueueDays' => self::MAX_QUEUE_DAYS,
        ];
    }

    private static function paginatedCards(string $tab, Builder $query, ?string $focusedPostId, ?User $requester): LengthAwarePaginator
    {
        $query->when($focusedPostId, fn (Builder $query) => $query->whereKey($focusedPostId));

        match ($tab) {
            self::TAB_QUEUE => $query->where('status', PostStatus::Scheduled)
                ->orderBy('posts.scheduled_at'),
            self::TAB_DRAFTS => $query->where('status', PostStatus::Draft)
                ->orderByRaw('CASE WHEN posts.scheduled_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('posts.scheduled_at')
                ->latest('posts.created_at'),
            self::TAB_APPROVALS => $query->pendingApproval()
                ->visiblePendingApprovalsFor($requester)
                ->orderByRaw('CASE WHEN posts.scheduled_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('posts.scheduled_at')
                ->orderBy('posts.approval_requested_at'),
            default => $query->whereIn('status', self::SENT_STATUSES)
                ->latestAttempt(),
        };

        $paginator = $query->orderBy('posts.id')->paginate((int) config('app.pagination.default'));

        self::decorate($paginator->getCollection());

        if ($tab === self::TAB_SENT) {
            self::attachMetrics($paginator->getCollection());
        }

        return $paginator;
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    public static function decorate(Collection $posts): void
    {
        $hasSchedule = [];
        $posts->pluck('user')->filter()->each(fn (User $user) => $user->makeHidden('avatarMedia'));

        foreach ($posts as $post) {
            $post->setAttribute('can_delete', ! PostStatusRules::blocksDeletion($post));

            foreach ($post->postPlatforms as $platform) {
                $account = $platform->socialAccount;

                if ($account === null) {
                    continue;
                }

                $hasSchedule[$account->id] ??= $account->hasPostingSchedule();
                $account->setAttribute('has_posting_schedule', $hasSchedule[$account->id]);
            }
        }
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    public static function attachMetrics(Collection $posts): void
    {
        $posts = $posts->filter(fn (Post $post): bool => in_array($post->status, self::SENT_STATUSES, true));
        $first = $posts->first();

        if ($first === null) {
            return;
        }

        $metrics = app(ReadPublicationAnalytics::class)->latestForPost(
            $first,
            $posts->flatMap(fn (Post $post) => $post->postPlatforms)->values(),
        );

        foreach ($posts as $post) {
            $post->setAttribute('metrics', $post->postPlatforms
                ->mapWithKeys(fn ($platform): array => [$platform->id => data_get($metrics, $platform->id)])
                ->all());
        }
    }
}
