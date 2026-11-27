<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\Admin\XPricing;
use App\Support\ThreadReplies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildXCostEstimate
{
    /**
     * An estimate of what X charged per UTC day, from X's public prices: the
     * Posts X says the project read that day, and the posts TryPost created
     * on X that day (each live thread reply counts as one), split by whether
     * X saw a link in them. Not what X billed: the console has that.
     *
     * @param  list<string>  $days  UTC calendar days, `Y-m-d`
     * @param  array<string, int>  $readsByDay  X's daily Post usage, by day
     * @return list<array{date: string, reads: int, created: int, created_with_link: int, cost_reads: float, cost_created: float, cost_created_with_link: float}>
     */
    public static function execute(array $days, array $readsByDay): array
    {
        if ($days === []) {
            return [];
        }

        $created = self::createdByDay(min($days), max($days));

        return collect($days)
            ->map(function (string $day) use ($created, $readsByDay): array {
                $reads = (int) data_get($readsByDay, $day, 0);
                $plain = (int) data_get($created, "{$day}.plain", 0);
                $withLink = (int) data_get($created, "{$day}.with_link", 0);

                return [
                    'date' => $day,
                    'reads' => $reads,
                    'created' => $plain,
                    'created_with_link' => $withLink,
                    'cost_reads' => round($reads * XPricing::POST_READ, 3),
                    'cost_created' => round($plain * XPricing::POST_CREATE, 3),
                    'cost_created_with_link' => round($withLink * XPricing::POST_CREATE_WITH_URL, 3),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, array{plain: int, with_link: int}>
     */
    private static function createdByDay(string $first, string $last): Collection
    {
        $sanitizer = app(ContentSanitizer::class);

        return Post::query()
            ->createdInTryPost()
            ->where('posts.platform', Platform::X)
            ->whereIn('posts.publish_status', PublishStatus::wentOutCases())
            ->whereBetween('posts.published_at', [
                CarbonImmutable::parse($first, 'UTC')->startOfDay(),
                CarbonImmutable::parse($last, 'UTC')->endOfDay(),
            ])
            ->get(['id', 'content', 'meta', 'thread_reply_ids', 'published_at', 'platform'])
            ->groupBy(fn (Post $post): string => $post->published_at->clone()->utc()->toDateString())
            ->map(function (Collection $posts) use ($sanitizer): array {
                $texts = $posts->flatMap(fn (Post $post): array => self::textsSent($post));
                $withLink = $texts->filter(fn (string $text): bool => $sanitizer->containsXLink($sanitizer->sanitize($text, Platform::X)))->count();

                return ['plain' => $texts->count() - $withLink, 'with_link' => $withLink];
            });
    }

    /**
     * The post's text and the text of each reply X has, in order: one X post each.
     *
     * @return list<string>
     */
    private static function textsSent(Post $post): array
    {
        $liveReplies = count((array) $post->thread_reply_ids);

        return [
            (string) $post->content,
            ...collect(ThreadReplies::of($post->meta))->take($liveReplies)->pluck('text')->all(),
        ];
    }
}
