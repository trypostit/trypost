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
     * @param  Collection<int, array{failed: bool}>  $posts
     * @return array{published: int, failed: int, failure_rate: float}
     */
    public static function of(Collection $posts): array
    {
        $failed = $posts->where('failed', true)->count();
        $total = $posts->count();

        return [
            'published' => $total - $failed,
            'failed' => $failed,
            'failure_rate' => Percent::of($failed, $total),
        ];
    }

    /**
     * @return list<PublishStatus>
     */
    private static function failedStatuses(): array
    {
        return [PublishStatus::Failed, PublishStatus::Rejected];
    }
}
