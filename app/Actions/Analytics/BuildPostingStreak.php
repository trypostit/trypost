<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\WeekStart;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Weeks in a row a workspace posted, for the streak badge in the sidebar.
 *
 * A week counts once anything went out in it: every publication Insights reads
 * (published through TryPost or directly on the network), plus what TryPost
 * published on the networks Insights does not read (LinkedIn, Google Business,
 * Telegram, Discord). Each TryPost post on an Insights network is also one of its
 * publications, so it is read once.
 */
final class BuildPostingStreak
{
    /** How far back a streak is read; a longer one reads as this long. */
    public const int WEEKS = 104;

    /**
     * @return array{weeks: int, posted_this_week: bool}
     */
    public function execute(Workspace $workspace, string $timezone, WeekStart $weekStart): array
    {
        $thisWeek = CarbonImmutable::now($timezone)->startOfWeek($weekStart->firstDay());
        $weeks = self::postingInstants([$workspace->id], $thisWeek->subWeeks(self::WEEKS)->utc())
            ->map(fn (object $row): string => self::weekOf($row->at, $timezone, $weekStart))
            ->unique()
            ->collect();

        return [
            'weeks' => self::streak($weeks, $thisWeek),
            'posted_this_week' => $weeks->contains($thisWeek->toDateString()),
        ];
    }

    /**
     * When each workspace posted since `$since`, from both sources.
     *
     * @param  list<string>|null  $workspaceIds  Null reads every workspace.
     * @return LazyCollection<int, object{workspace_id: string, at: string}>
     */
    public static function postingInstants(?array $workspaceIds, CarbonInterface $since): LazyCollection
    {
        $publications = DB::table('analytics_publications')
            ->select(['workspace_id', 'provider_published_at as at'])
            ->whereIn('platform', Platform::analyticsValues())
            ->where('provider_published_at', '>=', $since)
            ->when($workspaceIds !== null, fn (Builder $query): Builder => $query->whereIn('workspace_id', $workspaceIds));

        $posts = DB::table('posts')
            ->select(['workspace_id', 'published_at as at'])
            ->whereIn('platform', self::platformsOutsideInsights())
            ->whereIn('publish_status', collect(PublishStatus::wentOutCases())->map(fn (PublishStatus $status): string => $status->value)->all())
            ->where('published_at', '>=', $since)
            ->when($workspaceIds !== null, fn (Builder $query): Builder => $query->whereIn('workspace_id', $workspaceIds));

        return $publications->unionAll($posts)->cursor();
    }

    /** The first day of the week `$instant` falls in, in the viewer's zone and week. */
    public static function weekOf(string $instant, string $timezone, WeekStart $weekStart): string
    {
        return CarbonImmutable::parse($instant, 'UTC')
            ->setTimezone($timezone)
            ->startOfWeek($weekStart->firstDay())
            ->toDateString();
    }

    /**
     * Weeks in a row with a post, ending this week, or last week while this one has none yet.
     *
     * @param  Collection<int, string>  $weeks  The first day of each week with a post.
     */
    public static function streak(Collection $weeks, CarbonImmutable $thisWeek): int
    {
        $week = $thisWeek;

        if (! $weeks->contains($week->toDateString())) {
            $week = $week->subWeek();
        }

        $streak = 0;

        while ($weeks->contains($week->toDateString())) {
            $streak++;
            $week = $week->subWeek();
        }

        return $streak;
    }

    /**
     * @return list<string>
     */
    private static function platformsOutsideInsights(): array
    {
        return collect(Platform::cases())
            ->reject(fn (Platform $platform): bool => $platform->isIncludedInAnalytics())
            ->map(fn (Platform $platform): string => $platform->value)
            ->values()
            ->all();
    }
}
