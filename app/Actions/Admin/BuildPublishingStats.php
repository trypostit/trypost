<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Dto\Analytics\DateRange;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Support\Admin\PublishingCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildPublishingStats
{
    public const int TOP_ERRORS = 10;

    public const int TOP_CONTENT_TYPES = 12;

    /**
     * What TryPost published across every workspace on the range's calendar
     * days of the viewer's zone, on the given platforms or all of them: per
     * day, per network (every way of connecting it together, like the network
     * filter), and the errors that failed most. A post that went out counts
     * on the day it was published, a refused one on the day it failed
     * ({@see PublishingCounts::countedBetween()}); imported posts and posts
     * still on their way are left out. Everything but the days
     * ({@see self::days()}) is counted in SQL.
     *
     * @param  list<Platform>  $platforms  empty means every platform
     * @return array{
     *     days: list<array{date: string, published: int, failed: int}>,
     *     totals: array{published: int, failed: int, failure_rate: float},
     *     networks: list<array{platform: string, published: int, failed: int, failure_rate: float}>,
     *     top_errors: list<array{platform: string, network: string, category: string, message: string, count: int}>,
     *     categories: list<array{category: string, count: int}>,
     *     origins: list<array{origin: string, published: int, failed: int, failure_rate: float}>,
     *     content_types: list<array{platform: string, platform_label: string, content_type: string, label: string, published: int, failed: int, failure_rate: float}>
     * }
     */
    public static function execute(DateRange $range, array $platforms = []): array
    {
        $groups = self::counted($range, $platforms)
            ->tallyBy(['posts.platform', 'posts.publish_status', 'posts.created_via', 'posts.content_type'])
            ->toBase()
            ->get()
            ->map(fn (object $group): array => [
                'platform' => (string) $group->platform,
                'publish_status' => (string) $group->publish_status,
                'origin' => (string) ($group->created_via ?? CreatedVia::Web->value),
                'content_type' => (string) $group->content_type,
                'total' => (int) $group->total,
            ]);

        $failures = self::failures($range, $platforms);

        return [
            'days' => self::days($range, $platforms),
            'totals' => PublishingCounts::of($groups),
            'networks' => self::countedBy($groups, fn (array $group): string => Platform::from(data_get($group, 'platform'))->network())
                ->map(fn (Collection $networkGroups, string $network): array => ['platform' => $network, ...PublishingCounts::of($networkGroups)])
                ->values()
                ->all(),
            'top_errors' => $failures
                ->groupBy(fn (array $failure): string => collect([data_get($failure, 'platform'), data_get($failure, 'message')])->implode('|'))
                ->map(fn (Collection $group): array => [
                    'platform' => data_get($group->first(), 'platform'),
                    'network' => Platform::from(data_get($group->first(), 'platform'))->network(),
                    'category' => (string) $group->groupBy('category')->map->sum('total')->sortDesc()->keys()->first(),
                    'message' => data_get($group->first(), 'message'),
                    'count' => (int) $group->sum('total'),
                ])
                ->sortByDesc('count')
                ->take(self::TOP_ERRORS)
                ->values()
                ->all(),
            'categories' => $failures
                ->groupBy('category')
                ->map(fn (Collection $group): int => (int) $group->sum('total'))
                ->sortDesc()
                ->map(fn (int $count, string $category): array => ['category' => $category, 'count' => $count])
                ->values()
                ->all(),
            'origins' => self::countedBy($groups, fn (array $group): string => data_get($group, 'origin'))
                ->map(fn (Collection $originGroups, string $origin): array => ['origin' => $origin, ...PublishingCounts::of($originGroups)])
                ->values()
                ->all(),
            'content_types' => self::countedBy(
                $groups->filter(fn (array $group): bool => filled(data_get($group, 'content_type'))),
                fn (array $group): string => collect([data_get($group, 'platform'), data_get($group, 'content_type')])->implode('|'),
            )
                ->map(fn (Collection $typeGroups): array => [
                    'platform' => data_get($typeGroups->first(), 'platform'),
                    'platform_label' => Platform::from(data_get($typeGroups->first(), 'platform'))->label(),
                    'content_type' => data_get($typeGroups->first(), 'content_type'),
                    'label' => self::contentTypeLabel(data_get($typeGroups->first(), 'content_type')),
                    ...PublishingCounts::of($typeGroups),
                ])
                ->take(self::TOP_CONTENT_TYPES)
                ->values()
                ->all(),
        ];
    }

    /**
     * What went out and what failed on each of the range's days, bucketed
     * here from the few columns they need, so PostgreSQL and MySQL agree.
     * The rows are streamed and only their counts per day kept.
     *
     * @param  list<Platform>  $platforms  empty means every platform
     * @return list<array{date: string, published: int, failed: int}>
     */
    public static function days(DateRange $range, array $platforms = []): array
    {
        $counts = self::counted($range, $platforms)
            ->toBase()
            ->select(['posts.publish_status', 'posts.published_at', 'posts.publication_updated_at'])
            ->cursor()
            ->countBy(fn (object $post): string => "{$range->localDate(PublishingCounts::countedAt($post))}|{$post->publish_status}")
            ->collect();

        return collect($range->calendarDays())
            ->map(function (string $date) use ($counts): array {
                $dayCounts = PublishingCounts::of(collect(PublishingCounts::countedStatuses())->map(fn (PublishStatus $status): array => [
                    'publish_status' => $status->value,
                    'total' => (int) $counts->get("{$date}|{$status->value}", 0),
                ]));

                return ['date' => $date, 'published' => data_get($dayCounts, 'published'), 'failed' => data_get($dayCounts, 'failed')];
            })
            ->all();
    }

    /**
     * The posts the page counts: sent from TryPost, published within the
     * range or refused by the network within it.
     *
     * @param  list<Platform>  $platforms
     * @return Builder<Post>
     */
    private static function counted(DateRange $range, array $platforms): Builder
    {
        return PublishingCounts::countedBetween(Post::query()->createdInTryPost(), $range->startsAt(), $range->endsAt(), PublishingCounts::countedStatuses())
            ->when(filled($platforms), fn (Builder $query): Builder => $query->whereIn('posts.platform', $platforms));
    }

    /**
     * The failures counted by network, error message and category. A failure
     * recorded without a category is unknown, as on the failed posts page.
     *
     * @param  list<Platform>  $platforms
     * @return Collection<int, array{platform: string, message: string, category: string, total: int}>
     */
    private static function failures(DateRange $range, array $platforms): Collection
    {
        return self::counted($range, $platforms)
            ->whereIn('posts.publish_status', PublishingCounts::failedStatuses())
            ->tallyBy(['posts.platform', 'posts.error_message', 'posts.error_context->category as category'])
            ->toBase()
            ->get()
            ->map(fn (object $failure): array => [
                'platform' => (string) $failure->platform,
                'message' => (string) $failure->error_message,
                'category' => ErrorCategory::tryFrom((string) $failure->category)?->value ?? ErrorCategory::Unknown->value,
                'total' => (int) $failure->total,
            ]);
    }

    /**
     * The tallied groups gathered under a key, the busiest first.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     * @param  callable(array<string, mixed>): string  $key
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private static function countedBy(Collection $groups, callable $key): Collection
    {
        return $groups
            ->groupBy($key)
            ->sortByDesc(fn (Collection $group): int => (int) $group->sum('total'));
    }

    private static function contentTypeLabel(string $contentType): string
    {
        return ContentType::tryFrom($contentType)?->label() ?? $contentType;
    }
}
