<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\User\WeekStart;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Where a streak stands among every workspace with app access, for the "Top X%"
 * in the streak popover. Recomputed daily on Monday weeks in UTC, close enough
 * to rank a streak whatever week its viewer starts on.
 */
final class RankPostingStreaks
{
    /** The shares the popover names, smallest first; a streak below the last one gets none. */
    public const array TOP_PERCENTS = [1, 5, 10, 25, 50];

    private const string CACHE_KEY = 'posting-streak:ranks';

    public function execute(): void
    {
        $thisWeek = CarbonImmutable::now('UTC')->startOfWeek(WeekStart::Monday->firstDay());
        $weeks = [];

        BuildPostingStreak::postingInstants(null, $thisWeek->subWeeks(BuildPostingStreak::WEEKS - 1))
            ->each(function (object $row) use (&$weeks): void {
                $weeks[$row->workspace_id][BuildPostingStreak::weekOf($row->at, 'UTC', WeekStart::Monday)] = true;
            });

        $streaks = Workspace::query()
            ->with('account.subscriptions')
            ->lazyById()
            ->filter(fn (Workspace $workspace): bool => $workspace->account?->hasAppAccess() === true)
            ->map(fn (Workspace $workspace): int => BuildPostingStreak::streak(collect(array_keys(data_get($weeks, $workspace->id, []))), $thisWeek))
            ->collect();

        Cache::forever(self::CACHE_KEY, self::thresholds($streaks));
    }

    /**
     * The fewest weeks each "Top X%" needs: one past the longest streak still
     * shared by more than that share of the workspaces, so the rank keeps one
     * number per share instead of every workspace's streak.
     *
     * @param  Collection<int, int>  $streaks
     * @return array<int, int>
     */
    private static function thresholds(Collection $streaks): array
    {
        if ($streaks->isEmpty()) {
            return [];
        }

        $running = 0;
        $atLeast = $streaks->countBy()
            ->sortKeysDesc()
            ->map(function (int $count) use (&$running): int {
                $running += $count;

                return $running;
            });

        return collect(self::TOP_PERCENTS)
            ->mapWithKeys(fn (int $percent): array => [
                $percent => ($atLeast
                    ->filter(fn (int $count, int $streak): bool => $streak >= 1 && $count / $streaks->count() * 100 > $percent)
                    ->keys()
                    ->max() ?? 0) + 1,
            ])
            ->all();
    }

    /** The smallest "Top X%" a streak reaches among the ranked workspaces, or null below the last one. */
    public static function topPercent(int $weeks): ?int
    {
        $thresholds = collect(Cache::get(self::CACHE_KEY, []));

        if ($weeks < 1) {
            return null;
        }

        return $thresholds->keys()->first(fn (int $percent): bool => $weeks >= $thresholds->get($percent));
    }
}
