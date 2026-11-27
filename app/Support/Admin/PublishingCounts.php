<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Enums\Post\PublishStatus;
use App\Support\Percent;
use Illuminate\Support\Collection;

/**
 * How the backoffice counts what TryPost sent to a network: a post went out
 * (published, or deleted there since) or the network refused it (failed, or
 * rejected in review). Posts still on their way are not counted.
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

    public static function isFailed(string $publishStatus): bool
    {
        return collect(self::failedStatuses())
            ->contains(fn (PublishStatus $failed): bool => $failed->value === $publishStatus);
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
     * What the backoffice calls failed everywhere: the network refused the
     * post, or rejected it in review.
     *
     * @return list<PublishStatus>
     */
    public static function failedStatuses(): array
    {
        return [PublishStatus::Failed, PublishStatus::Rejected];
    }
}
