<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Post\Queue\BuildQueueTimeline;
use App\Enums\Post\Status as PostStatus;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\RequestIds;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class BuildCalendarPageProps
{
    public const VIEWS = ['week', 'month'];

    public const STATUS_ALL = 'all';

    public const STATUSES = [self::STATUS_ALL, 'drafts', 'scheduled', 'sent'];

    public const UNDATED_PAGE_NAME = 'undated_page';

    /**
     * @return array<string, mixed>
     */
    public static function handle(Request $request, Workspace $workspace, ?SocialAccount $channel, ?string $view): array
    {
        $userTimezone = Timezone::normalize($request->user()->timezone);
        $timezone = BuildPublishPageProps::displayTimezone($request->query('tz'), $userTimezone);
        $view = in_array($view, self::VIEWS, true) ? $view : 'week';
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : self::STATUS_ALL;

        $labelIds = RequestIds::uuidList($request->collect('labels'));
        $untagged = $request->boolean('untagged');
        $requestedChannelIds = $channel ? [] : RequestIds::uuidList($request->collect('channels')->unique());
        $scopedChannelIds = $channel ? [$channel->id] : ($requestedChannelIds !== [] ? $requestedChannelIds : null);

        $weekStartsOn = $request->user()->week_starts_on;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $weekStart = (self::parseDate($request->query('week'), $timezone) ?? $today)->startOfWeek($weekStartsOn->firstDay());
        $monthDate = (self::parseDate($request->query('month'), $timezone) ?? $today)->startOfMonth();

        [$rangeStart, $rangeEnd] = $view === 'month'
            ? [$monthDate->startOfWeek($weekStartsOn->firstDay()), $monthDate->endOfMonth()->endOfWeek($weekStartsOn->lastDay())]
            : [$weekStart, $weekStart->endOfWeek($weekStartsOn->lastDay())];

        $workspaceAccounts = null;
        $filterAccounts = function () use (&$workspaceAccounts, $channel, $workspace): Collection {
            return $workspaceAccounts ??= $channel ? collect() : $workspace->socialAccounts()->get();
        };

        $scopedPosts = fn (): Builder => BuildPublishPageProps::cardQuery($workspace->posts(), $scopedChannelIds, $labelIds, $untagged)
            ->when($scopedChannelIds !== null, fn (Builder $query) => $query->whereHas(
                'postPlatforms',
                fn (Builder $platforms) => $platforms->enabled()->whereIn('social_account_id', $scopedChannelIds),
            ))
            ->visiblePendingApprovalsFor(BuildPublishPageProps::pendingApprovalsRequester($request->user(), $workspace));

        $range = [$rangeStart->utc(), $rangeEnd->utc()];
        $sent = BuildPublishPageProps::SENT_STATUSES;

        $posts = self::whereStatus($scopedPosts(), $status)
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $published): Builder => $published
                    ->whereIn('status', $sent)
                    ->whereNotNull('published_at')
                    ->whereBetween('published_at', $range))
                ->orWhere(fn (Builder $planned): Builder => $planned
                    ->where(fn (Builder $unpublished): Builder => $unpublished->whereNotIn('status', $sent)->orWhereNull('published_at'))
                    ->whereBetween('scheduled_at', $range)))
            ->get()
            ->each(fn (Post $post) => $post->setAttribute('calendar_at', self::calendarAt($post)->utc()->toIso8601ZuluString()));

        BuildPublishPageProps::decorate($posts);
        BuildPublishPageProps::attachMetrics($posts);

        $posts = $posts
            ->sortBy('calendar_at')
            ->values()
            ->groupBy(fn (Post $post): string => CarbonImmutable::parse($post->calendar_at)->setTimezone($timezone)->format('Y-m-d'));

        $props = [
            'workspace' => $workspace,
            'scope' => $channel ? 'channel' : 'all',
            'channel' => fn (): ?array => $channel ? BuildPublishPageProps::channelHeader($channel, $weekStartsOn) : null,
            'posts' => $posts,
            'currentWeekStart' => $weekStart->format('Y-m-d'),
            'currentMonth' => $monthDate->format('Y-m-d'),
            'view' => $view,
            'displayTimezone' => $timezone,
            'timezones' => fn (): array => Timezone::options(),
            'labels' => fn () => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'filters' => [
                'labels' => $labelIds,
                'untagged' => $untagged,
                'channels' => $requestedChannelIds,
                'status' => $status,
            ],
            'filterAccounts' => fn () => SocialAccountResource::collection($filterAccounts()),
        ];

        if ($request->boolean('undated')) {
            $props['undatedDrafts'] = Inertia::scroll(fn (): LengthAwarePaginator => $scopedPosts()
                ->where('status', PostStatus::Draft)
                ->whereNull('scheduled_at')
                ->latest('created_at')
                ->orderBy('id')
                ->paginate((int) config('app.pagination.default'), ['*'], self::UNDATED_PAGE_NAME));
        }

        if ($request->boolean('slots')) {
            $props['slots'] = fn (): array => self::slots(
                $workspace,
                match (true) {
                    $channel !== null => collect([$channel]),
                    $requestedChannelIds !== [] => $filterAccounts()->whereIn('id', $requestedChannelIds)->values(),
                    default => $filterAccounts(),
                },
                $timezone,
                $rangeStart,
                $rangeEnd,
                $labelIds === [] && ! $untagged && in_array($status, [self::STATUS_ALL, 'scheduled'], true),
            );
        }

        return $props;
    }

    /**
     * Empty posting slots of the visible range, bucketed by day of the display time zone.
     *
     * @param  Collection<int, SocialAccount>  $channels
     * @return array<string, list<array{at: string, channel_id: string}>>
     */
    private static function slots(Workspace $workspace, Collection $channels, string $timezone, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, bool $visible): array
    {
        if (! $visible || $rangeEnd->isPast()) {
            return [];
        }

        $slots = [];

        foreach (BuildQueueTimeline::handle($workspace, $channels, $timezone, $rangeEnd) as $day) {
            foreach ($day['items'] as $item) {
                if ($item['type'] !== 'slot' || CarbonImmutable::parse($item['at'])->lessThan($rangeStart)) {
                    continue;
                }

                $slots[$day['date']][] = ['at' => $item['at'], 'channel_id' => $item['channel_id']];
            }
        }

        return $slots;
    }

    private static function calendarAt(Post $post): CarbonInterface
    {
        return in_array($post->status, BuildPublishPageProps::SENT_STATUSES, true) && $post->published_at !== null
            ? $post->published_at
            : $post->scheduled_at;
    }

    private static function whereStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'drafts' => $query->where('status', PostStatus::Draft),
            'scheduled' => $query->where('status', PostStatus::Scheduled),
            'sent' => $query->whereIn('status', BuildPublishPageProps::SENT_STATUSES),
            default => $query,
        };
    }

    private static function parseDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) ?: null;
    }
}
