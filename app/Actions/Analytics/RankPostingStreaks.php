<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\User\WeekStart;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;

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

    /** Fewer workspaces than this rank nothing: a small install would only rank itself. */
    public const int MIN_RANKED = 10;

    private const int CHUNK = 200;

    public function execute(): void
    {
        $thisWeek = CarbonImmutable::now('UTC')->startOfWeek(WeekStart::Monday->firstDay());
        $since = $thisWeek->subWeeks(BuildPostingStreak::WEEKS - 1);

        $streaks = Workspace::query()
            ->with('account.subscriptions')
            ->lazyById(self::CHUNK)
            ->filter(fn (Workspace $workspace): bool => $workspace->account?->hasAppAccess() === true)
            ->chunk(self::CHUNK)
            ->flatMap(fn (LazyCollection $workspaces): Collection => self::streaksOf($workspaces->pluck('id')->values()->all(), $since, $thisWeek))
            ->collect();

        Cache::forever(self::CACHE_KEY, self::thresholds($streaks));
    }

    /**
     * The streak of each workspace, read a chunk at a time so the posts of every
     * workspace are never held at once.
     *
     * @param  list<string>  $workspaceIds
     * @return Collection<int, int>
     */
    private static function streaksOf(array $workspaceIds, CarbonImmutable $since, CarbonImmutable $thisWeek): Collection
    {
        $weeks = BuildPostingStreak::postingInstants($workspaceIds, $since)
            ->collect()
            ->groupBy('workspace_id')
            ->map(fn (Collection $rows): Collection => $rows
                ->map(fn (object $row): string => BuildPostingStreak::weekOf($row->at, 'UTC', WeekStart::Monday))
                ->unique());

        return collect($workspaceIds)->map(fn (string $id): int => BuildPostingStreak::streak($weeks->get($id, collect()), $thisWeek));
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
        if ($streaks->count() < self::MIN_RANKED) {
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
