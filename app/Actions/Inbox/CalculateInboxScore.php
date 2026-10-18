<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ScoreLevel;
use App\Models\InboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class CalculateInboxScore
{
    public const int WINDOW_DAYS = 7;

    public const int CACHE_MINUTES = 10;

    public const int FAST_REPLY_SECONDS = 2 * 3600;

    public const int SLOW_REPLY_SECONDS = 48 * 3600;

    /** @var array{rate: float, speed: float, consistency: float} */
    public const array WEIGHTS = ['rate' => 0.5, 'speed' => 0.3, 'consistency' => 0.2];

    /**
     * How the workspace answers its comments and reviews over the last seven
     * days of the user's time zone (today included): response rate, capped
     * while there are few comments, response speed and consistency, weighted
     * into one score. Mentions and direct messages never count. Without a
     * comment in the window there is no score.
     *
     * @param  list<string>  $channelIds  only these channels; empty means every channel
     * @return array{score: ?int, level: ?string, response_rate: ?int, response_speed: ?int, consistency: ?int, comments_count: int, answered_count: int, starts_at: string, ends_at: string, timezone: string}
     */
    public static function execute(Workspace $workspace, User $user, array $channelIds = []): array
    {
        $timezone = self::timezoneOf($user);
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($timezone)->startOfDay();
        $days = collect(range(self::WINDOW_DAYS - 1, 0))
            ->map(fn (int $daysAgo): array => [$today->subDays($daysAgo), $today->subDays($daysAgo)->addDay()])
            ->all();
        $startsAt = $days[0][0];

        $totals = (array) self::inbound($workspace, $channelIds, $startsAt)
            ->selectScoreTotals($days)
            ->toBase()
            ->first();

        $commentsCount = (int) data_get($totals, 'comments_count', 0);
        $answeredCount = (int) data_get($totals, 'answered_count', 0);
        $window = [
            'comments_count' => $commentsCount,
            'answered_count' => $answeredCount,
            'starts_at' => $startsAt->utc()->toIso8601String(),
            'ends_at' => $now->utc()->toIso8601String(),
            'timezone' => $timezone,
        ];

        if ($commentsCount === 0) {
            return ['score' => null, 'level' => null, 'response_rate' => null, 'response_speed' => null, 'consistency' => null, ...$window];
        }

        $rate = min($answeredCount / $commentsCount * 100, self::rateCap($commentsCount));
        $speed = self::speed($workspace, $channelIds, $startsAt, $answeredCount);
        $daysWithComments = collect(array_keys($days))->filter(fn (int $index): bool => (int) data_get($totals, "day_{$index}_comments") === 1);
        $daysReplied = $daysWithComments->filter(fn (int $index): bool => (int) data_get($totals, "day_{$index}_replied") === 1);
        $consistency = $daysReplied->count() / $daysWithComments->count() * 100;
        $score = (int) round(self::WEIGHTS['rate'] * $rate + self::WEIGHTS['speed'] * $speed + self::WEIGHTS['consistency'] * $consistency);

        return [
            'score' => $score,
            'level' => ScoreLevel::fromScore($score)->value,
            'response_rate' => (int) round($rate),
            'response_speed' => (int) round($speed),
            'consistency' => (int) round($consistency),
            ...$window,
        ];
    }

    /**
     * The score as read in the last ten minutes for this workspace, channel
     * scope, time zone and day; any inbox write in the workspace starts over.
     *
     * @param  list<string>  $channelIds
     * @return array{score: ?int, level: ?string, response_rate: ?int, response_speed: ?int, consistency: ?int, comments_count: int, answered_count: int, starts_at: string, ends_at: string, timezone: string}
     */
    public static function cached(Workspace $workspace, User $user, array $channelIds = []): array
    {
        $timezone = self::timezoneOf($user);
        $scope = collect($channelIds)->sort()->implode(',');
        $day = CarbonImmutable::now($timezone)->toDateString();
        $version = Cache::get(self::versionKey($workspace->id), 0);
        $key = "inbox-score.{$workspace->id}.{$version}.{$timezone}.{$day}.".hash('xxh128', $scope);

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn (): array => self::execute($workspace, $user, $channelIds));
    }

    public static function forget(string $workspaceId): void
    {
        Cache::add(self::versionKey($workspaceId), 0);
        Cache::increment(self::versionKey($workspaceId));
    }

    /**
     * Few comments cap the response rate: 20% for 1 to 4, 60% for 5 to 9.
     */
    public static function rateCap(int $commentsCount): int
    {
        return match (true) {
            $commentsCount < 5 => 20,
            $commentsCount < 10 => 60,
            default => 100,
        };
    }

    /**
     * Full marks for a first reply within two hours, nothing from 48 hours,
     * a straight line between.
     */
    public static function speedOf(int $seconds): float
    {
        return match (true) {
            $seconds <= self::FAST_REPLY_SECONDS => 100.0,
            $seconds >= self::SLOW_REPLY_SECONDS => 0.0,
            default => (self::SLOW_REPLY_SECONDS - $seconds) / (self::SLOW_REPLY_SECONDS - self::FAST_REPLY_SECONDS) * 100,
        };
    }

    /**
     * @param  list<string>  $channelIds
     */
    private static function speed(Workspace $workspace, array $channelIds, CarbonImmutable $startsAt, int $answeredCount): float
    {
        if ($answeredCount === 0) {
            return 0.0;
        }

        $total = self::inbound($workspace, $channelIds, $startsAt)
            ->whereNotNull('answered_at')
            ->toBase()
            ->get(['platform_created_at', 'answered_at'])
            ->sum(fn (object $message): float => self::speedOf(max(0, (int) CarbonImmutable::parse($message->platform_created_at, 'UTC')->diffInSeconds(CarbonImmutable::parse($message->answered_at, 'UTC')))));

        return $total / $answeredCount;
    }

    /**
     * @param  list<string>  $channelIds
     * @return Builder<InboxMessage>
     */
    private static function inbound(Workspace $workspace, array $channelIds, CarbonImmutable $startsAt): Builder
    {
        return InboxMessage::query()
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNull('removed_at')
            ->where('platform_created_at', '>=', $startsAt->utc())
            ->when(filled($channelIds), fn (Builder $query) => $query->whereIn('social_account_id', $channelIds))
            ->whereHas('conversation', fn (Builder $conversations) => $conversations->visibleInTab($workspace, InboxTab::Comments));
    }

    private static function timezoneOf(User $user): string
    {
        return filled($user->timezone) ? (string) $user->timezone : 'UTC';
    }

    private static function versionKey(string $workspaceId): string
    {
        return "inbox-score:version:{$workspaceId}";
    }
}
