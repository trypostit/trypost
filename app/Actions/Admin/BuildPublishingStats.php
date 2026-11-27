<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Post\CreatedVia;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Support\Admin\PublishingCounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildPublishingStats
{
    public const int TOP_ERRORS = 10;

    public const int TOP_CONTENT_TYPES = 12;

    /**
     * What TryPost published across every workspace between two calendar
     * days of the viewer's zone (both included), on the given platforms or
     * all of them: per day,
     * per network (every way of connecting it together, like the network
     * filter), and the errors that failed most. A post counts on the day its publication last moved (`publication_updated_at`);
     * imported posts and posts still on their way are left out. Days are
     * bucketed here, not in SQL, so PostgreSQL and MySQL agree.
     *
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
    public static function execute(string $start, string $end, string $timezone, array $platforms = []): array
    {
        $since = CarbonImmutable::parse($start, $timezone)->startOfDay();
        $until = CarbonImmutable::parse($end, $timezone)->endOfDay();
        $days = (int) $since->diffInDays($until->startOfDay()) + 1;

        $posts = Post::query()
            ->createdInTryPost()
            ->whereIn('posts.publish_status', PublishingCounts::countedStatuses())
            ->whereBetween('posts.publication_updated_at', [$since, $until])
            ->when(filled($platforms), fn ($query) => $query->whereIn('posts.platform', $platforms))
            ->toBase()
            ->get(['posts.platform', 'posts.publish_status', 'posts.publication_updated_at', 'posts.error_message', 'posts.error_context', 'posts.created_via', 'posts.content_type'])
            ->map(fn (object $post): array => [
                'platform' => (string) $post->platform,
                'failed' => PublishingCounts::isFailed((string) $post->publish_status),
                'date' => CarbonImmutable::parse($post->publication_updated_at, 'UTC')->setTimezone($timezone)->toDateString(),
                'error' => (string) data_get($post, 'error_message'),
                'category' => self::category(data_get($post, 'error_context')),
                'origin' => (string) (data_get($post, 'created_via') ?? CreatedVia::Web->value),
                'content_type' => (string) data_get($post, 'content_type'),
            ]);
        $failures = $posts->where('failed', true);

        $byDate = $posts->groupBy('date');

        return [
            'days' => collect(range(0, $days - 1))
                ->map(function (int $offset) use ($since, $byDate): array {
                    $date = $since->addDays($offset)->toDateString();
                    $counts = PublishingCounts::of($byDate->get($date, collect()));

                    return ['date' => $date, 'published' => $counts['published'], 'failed' => $counts['failed']];
                })
                ->all(),
            'totals' => PublishingCounts::of($posts),
            'networks' => $posts->groupBy(fn (array $post): string => Platform::from($post['platform'])->network())
                ->map(fn (Collection $networkPosts, string $network): array => ['platform' => $network, ...PublishingCounts::of($networkPosts)])
                ->sortByDesc(fn (array $network): int => $network['published'] + $network['failed'])
                ->values()
                ->all(),
            'top_errors' => $failures
                ->groupBy(fn (array $post): string => "{$post['platform']}|{$post['error']}")
                ->map(fn (Collection $group): array => [
                    'platform' => $group->first()['platform'],
                    'network' => Platform::from($group->first()['platform'])->network(),
                    'category' => (string) $group->countBy('category')->sortDesc()->keys()->first(),
                    'message' => $group->first()['error'],
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->take(self::TOP_ERRORS)
                ->values()
                ->all(),
            'categories' => $failures
                ->countBy('category')
                ->sortDesc()
                ->map(fn (int $count, string $category): array => ['category' => $category, 'count' => $count])
                ->values()
                ->all(),
            'origins' => $posts->groupBy('origin')
                ->map(fn (Collection $group, string $origin): array => ['origin' => $origin, ...PublishingCounts::of($group)])
                ->sortByDesc(fn (array $origin): int => $origin['published'] + $origin['failed'])
                ->values()
                ->all(),
            'content_types' => $posts->filter(fn (array $post): bool => filled($post['content_type']))
                ->groupBy(fn (array $post): string => "{$post['platform']}|{$post['content_type']}")
                ->map(fn (Collection $group): array => [
                    'platform' => $group->first()['platform'],
                    'platform_label' => Platform::from($group->first()['platform'])->label(),
                    'content_type' => $group->first()['content_type'],
                    'label' => ContentType::tryFrom($group->first()['content_type'])?->label() ?? $group->first()['content_type'],
                    ...PublishingCounts::of($group),
                ])
                ->sortByDesc(fn (array $type): int => $type['published'] + $type['failed'])
                ->take(self::TOP_CONTENT_TYPES)
                ->values()
                ->all(),
        ];
    }

    /**
     * The error category a failure was filed under; one recorded without a
     * category is unknown, as on the failed posts page. The raw read carries
     * the JSON column as text.
     */
    private static function category(?string $errorContext): string
    {
        if (blank($errorContext)) {
            return ErrorCategory::Unknown->value;
        }

        $context = json_decode($errorContext, true);

        if (! is_array($context)) {
            return ErrorCategory::Unknown->value;
        }

        return ErrorCategory::tryFromContext($context)?->value ?? ErrorCategory::Unknown->value;
    }
}
