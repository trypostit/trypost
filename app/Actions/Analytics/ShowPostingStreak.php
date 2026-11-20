<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The streak badge the sidebar shows on every page, cached for a few minutes per
 * user and workspace so a page change never reads the posts again. A post going
 * out forgets the workspace's badges, so the next page counts it at once.
 */
final class ShowPostingStreak
{
    private const int CACHE_MINUTES = 10;

    /**
     * The streak of the user's current workspace, read in their zone and week,
     * while its account has app access; null hides the badge.
     *
     * @return array{weeks: int, posted_this_week: bool, top_percent: ?int}|null
     */
    public static function forSidebar(?User $user): ?array
    {
        $workspace = $user?->currentWorkspace;

        if (blank($workspace)) {
            return null;
        }

        if ($workspace->account?->hasAppAccess() !== true) {
            return null;
        }

        return self::cached($user, $workspace);
    }

    /**
     * Null while the workspace has no streak: the sidebar shows no badge then.
     * The streak is cached even at zero weeks, since the cache keeps no null.
     *
     * @return array{weeks: int, posted_this_week: bool, top_percent: ?int}|null
     */
    public static function cached(User $user, Workspace $workspace): ?array
    {
        $version = Cache::get(self::versionKey($workspace->id), '0');

        $streak = Cache::remember(
            "posting-streak:{$workspace->id}:{$version}:{$user->id}:{$user->timezone}:{$user->week_starts_on->value}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): array => self::build($user, $workspace),
        );

        if (data_get($streak, 'weeks') === 0) {
            return null;
        }

        return $streak;
    }

    /** Drops every member's cached badge of the workspace, once the change that calls it is committed. */
    public static function forget(string $workspaceId): void
    {
        DB::afterCommit(fn () => Cache::forever(self::versionKey($workspaceId), (string) Str::ulid()));
    }

    private static function versionKey(string $workspaceId): string
    {
        return "posting-streak:version:{$workspaceId}";
    }

    /**
     * @return array{weeks: int, posted_this_week: bool, top_percent: ?int}
     */
    private static function build(User $user, Workspace $workspace): array
    {
        $streak = app(BuildPostingStreak::class)->execute($workspace, $user->timezone, $user->week_starts_on);

        return [
            ...$streak,
            'top_percent' => RankPostingStreaks::topPercent(data_get($streak, 'weeks')),
        ];
    }
}
