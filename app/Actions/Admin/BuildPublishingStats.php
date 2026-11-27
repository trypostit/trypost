<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Dto\Analytics\DateRange;
use App\Enums\Post\CreatedVia;
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
     * filter), and the errors that failed most. A post counts on the day its
     * publication last moved (`publication_updated_at`); imported posts and
     * posts still on their way are left out. Everything but the days is
     * counted in SQL; days are bucketed here, from the few columns they need,
     * so PostgreSQL and MySQL agree.
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

        $byDate = self::counted($range, $platforms)
            ->toBase()
            ->get(['posts.publish_status', 'posts.publication_updated_at'])
            ->map(fn (object $post): array => [
                'date' => $range->localDate($post->publication_updated_at),
                'publish_status' => (string) $post->publish_status,
                'total' => 1,
            ])
            ->groupBy('date');

        $failures = self::failures($range, $platforms);

        return [
            'days' => collect($range->calendarDays())
                ->map(function (string $date) use ($byDate): array {
                    $counts = PublishingCounts::of($byDate->get($date, collect()));

                    return ['date' => $date, 'published' => $counts['published'], 'failed' => $counts['failed']];
                })
                ->all(),
            'totals' => PublishingCounts::of($groups),
            'networks' => self::countedBy($groups, fn (array $group): string => Platform::from(data_get($group, 'platform'))->network())
                ->map(fn (Collection $networkGroups, string $network): array => ['platform' => $network, ...PublishingCounts::of($networkGroups)])
                ->values()
                ->all(),
            'top_errors' => $failures
                ->groupBy(fn (array $failure): string => "{$failure['platform']}|{$failure['message']}")
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
                fn (array $group): string => "{$group['platform']}|{$group['content_type']}",
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
     * The posts the page counts: sent from TryPost, out on the network or
     * refused by it, their publication last moved within the range.
     *
     * @param  list<Platform>  $platforms
     * @return Builder<Post>
     */
    private static function counted(DateRange $range, array $platforms): Builder
    {
        return Post::query()
            ->createdInTryPost()
            ->whereIn('posts.publish_status', PublishingCounts::countedStatuses())
            ->whereBetween('posts.publication_updated_at', [$range->startsAt(), $range->endsAt()])
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
