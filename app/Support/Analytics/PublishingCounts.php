<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\Post\PublishStatus;
use App\Models\Post;
use App\Support\Percent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How the admin counts what TryPost sent to a network: a post went out
 * (published, or deleted there since) or the network refused it (failed, or
 * rejected in review). Posts still on their way are not counted.
 *
 * A post that went out counts at the moment it was published
 * (`published_at`): later writes on its publication (deleted on the network,
 * a TikTok id, Telegram reactions, a cover warning) move
 * `publication_updated_at` and must not make an old post count as sent
 * today. A refused post counts at its failure, the last time its publication
 * moved.
 */
final class PublishingCounts
{
    /**
     * @return list<PublishStatus>
     */
    public static function countedStatuses(): array
    {
        return [...PublishStatus::wentOutCases(), ...self::failedStatuses()];
    }

    /**
     * Keeps the posts in the given statuses that count between the two
     * moments: those that went out by when they were published, the refused
     * ones by when they failed.
     *
     * @param  Builder<Post>  $query
     * @param  list<PublishStatus>  $statuses
     * @return Builder<Post>
     */
    public static function countedBetween(Builder $query, CarbonInterface $from, CarbonInterface $until, array $statuses): Builder
    {
        $wentOut = collect($statuses)->filter(fn (PublishStatus $status): bool => $status->wentOut())->values()->all();
        $failed = collect($statuses)->filter(fn (PublishStatus $status): bool => collect(self::failedStatuses())->contains($status))->values()->all();

        return $query->where(fn (Builder $counted): Builder => $counted
            ->where(fn (Builder $sent): Builder => $sent
                ->whereIn('posts.publish_status', $wentOut)
                ->whereBetween('posts.published_at', [$from, $until]))
            ->orWhere(fn (Builder $refused): Builder => $refused
                ->whereIn('posts.publish_status', $failed)
                ->whereBetween('posts.publication_updated_at', [$from, $until])));
    }

    /**
     * The UTC moment a post read with its `publish_status`, `published_at`
     * and `publication_updated_at` counts at, as {@see self::countedBetween()}
     * filters it.
     */
    public static function countedAt(object $post): ?string
    {
        if (self::isFailed((string) data_get($post, 'publish_status'))) {
            return data_get($post, 'publication_updated_at');
        }

        return data_get($post, 'published_at');
    }

    /**
     * The counts of rows tallied by publication status: each row holds a
     * status and how many posts had it.
     *
     * @param  Collection<int, array{publish_status: string, total: int}>  $tallies
     * @return array{published: int, failed: int, failure_rate: float}
     */
    public static function of(Collection $tallies): array
    {
        $failed = (int) $tallies->filter(fn (array $tally): bool => self::isFailed((string) data_get($tally, 'publish_status')))->sum('total');
        $total = (int) $tallies->sum('total');

        return [
            'published' => $total - $failed,
            'failed' => $failed,
            'failure_rate' => Percent::of($failed, $total),
        ];
    }

    /**
     * What the admin calls failed everywhere: the network refused the
     * post, or rejected it in review.
     *
     * @return list<PublishStatus>
     */
    public static function failedStatuses(): array
    {
        return [PublishStatus::Failed, PublishStatus::Rejected];
    }

    private static function isFailed(string $publishStatus): bool
    {
        return collect(self::failedStatuses())
            ->contains(fn (PublishStatus $failed): bool => $failed->value === $publishStatus);
    }
}
