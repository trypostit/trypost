<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * The streak badge the sidebar shows on every page, cached for a few minutes per
 * user and workspace so a page change never reads the posts again.
 */
final class ShowPostingStreak
{
    private const int CACHE_MINUTES = 10;

    /**
     * Null while the workspace has no streak: the sidebar shows no badge then.
     *
     * @return array{weeks: int, posted_this_week: bool, top_percent: ?int}|null
     */
    public static function cached(User $user, Workspace $workspace): ?array
    {
        return Cache::remember(
            "posting-streak:{$workspace->id}:{$user->id}:{$user->timezone}:{$user->week_starts_on->value}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): ?array => self::build($user, $workspace),
        );
    }

    /**
     * @return array{weeks: int, posted_this_week: bool, top_percent: ?int}|null
     */
    private static function build(User $user, Workspace $workspace): ?array
    {
        $streak = app(BuildPostingStreak::class)->execute($workspace, $user->timezone, $user->week_starts_on);

        if (data_get($streak, 'weeks') === 0) {
            return null;
        }

        return [
            ...$streak,
            'top_percent' => RankPostingStreaks::topPercent(data_get($streak, 'weeks')),
        ];
    }
}
