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
        $workspaceIds = Workspace::query()
            ->with('account.subscriptions')
            ->get()
            ->filter(fn (Workspace $workspace): bool => (bool) $workspace->account?->hasAppAccess())
            ->pluck('id');

        $weeks = BuildPostingStreak::postingInstants($workspaceIds->all(), $thisWeek->subWeeks(BuildPostingStreak::WEEKS))
            ->groupBy('workspace_id')
            ->map(fn (Collection $rows): Collection => $rows->map(fn (object $row): string => BuildPostingStreak::weekOf($row->at, 'UTC', WeekStart::Monday))->unique()->collect());

        $streaks = $workspaceIds
            ->map(fn (string $id): int => BuildPostingStreak::streak($weeks->get($id, collect()), $thisWeek))
            ->sortDesc()
            ->values()
            ->all();

        Cache::forever(self::CACHE_KEY, $streaks);
    }

    /** The smallest "Top X%" a streak reaches among the ranked workspaces, or null below the last one. */
    public static function topPercent(int $weeks): ?int
    {
        $streaks = collect(Cache::get(self::CACHE_KEY, []));

        if ($weeks < 1) {
            return null;
        }

        if ($streaks->isEmpty()) {
            return null;
        }

        $share = $streaks->filter(fn (int $streak): bool => $streak >= $weeks)->count() / $streaks->count() * 100;

        return collect(self::TOP_PERCENTS)->first(fn (int $percent): bool => $share <= $percent);
    }
}
