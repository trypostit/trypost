<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\User\WeekStart;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Analytics\MetricComparison;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BuildWorkspaceAnalyticsReport
{
    public function __construct(
        private readonly BuildPublicationAnalyticsReport $publications,
        private readonly BuildFollowerAnalyticsReport $followers,
        private readonly GetAnalyticsBounds $bounds,
        private readonly ResolveAnalyticsDateRange $dateRange,
        private readonly ResolveAnalyticsAccountKey $accountKey,
    ) {}

    /**
     * @param  array{start?: string, end?: string, observed_through?: string, timezone?: string}  $selected
     * @param  array<string, string>|null  $channelKeys  Analytics key of each selected social account (already resolved for tenancy), keyed by account id; null means every channel.
     * @return array<string, mixed>
     */
    public function forSelection(Workspace $workspace, array $selected = [], ?array $channelKeys = null, bool $clampToBounds = true, WeekStart $weekStart = WeekStart::DEFAULT): array
    {
        ['bounds' => $bounds, 'range' => $range] = $this->resolveRange($workspace, $selected, $this->keys($channelKeys), $clampToBounds);

        return $this->forRange($workspace, $range, $bounds, $channelKeys, $weekStart);
    }

    /**
     * @param  array{start?: string, end?: string, observed_through?: string, timezone?: string}  $selected
     * @param  list<string>|null  $accountKeys
     * @return array{bounds: array{min: ?string, max: ?string}, range: DateRange}
     */
    public function resolveRange(Workspace $workspace, array $selected = [], ?array $accountKeys = null, bool $clampToBounds = true): array
    {
        $bounds = $this->bounds->execute($workspace, $accountKeys, data_get($selected, 'timezone', 'UTC'));

        return ['bounds' => $bounds, 'range' => $this->dateRange->execute($bounds, $selected, $clampToBounds)];
    }

    /**
     * @param  array{min: ?string, max: ?string}|null  $bounds
     * @return array<string, mixed>
     */
    public function execute(Workspace $workspace, DateRange $range, ?array $bounds = null, ?SocialAccount $channel = null, ?string $accountKey = null, WeekStart $weekStart = WeekStart::DEFAULT, ?PublicationFilter $filter = null): array
    {
        $channelKeys = $channel === null ? null : [$channel->id => $accountKey ?? $this->accountKey->for($channel)];

        return $this->forRange($workspace, $range, $bounds, $channelKeys, $weekStart, $filter);
    }

    /**
     * @param  array{min: ?string, max: ?string}|null  $bounds
     * @param  array<string, string>|null  $channelKeys
     * @param  PublicationFilter|null  $filter  Narrows post metrics only; followers stay account-wide.
     * @return array<string, mixed>
     */
    public function forRange(Workspace $workspace, DateRange $range, ?array $bounds, ?array $channelKeys, WeekStart $weekStart = WeekStart::DEFAULT, ?PublicationFilter $filter = null): array
    {
        $accountKeys = $this->keys($channelKeys);
        $previous = $range->previous();
        $publications = $this->publications->execute($workspace, $previous, $range, $accountKeys, $weekStart, $filter);
        $followers = $this->followers->execute($workspace, $previous, $range, $channelKeys);
        $current = data_get($publications, 'current_totals');
        $prior = data_get($publications, 'previous_totals');
        $currentFollowers = data_get($followers, 'current_total');
        $previousFollowers = data_get($followers, 'previous_total');
        $currentNet = data_get($followers, 'current_net');

        $topPosts = data_get($publications, 'top_posts');
        $postAccounts = data_get($publications, 'posts.accounts');
        $followerAccounts = data_get($followers, 'followers.accounts');
        $performance = data_get($publications, 'performance');
        $liveAccounts = $this->liveAccountsByKey($workspace, [
            ...array_column($postAccounts, 'social_account_key'),
            ...array_column($followerAccounts, 'social_account_key'),
            ...array_column($performance, 'social_account_key'),
            ...array_column(data_get($topPosts, 'reactions'), 'social_account_key'),
            ...array_column(data_get($topPosts, 'comments'), 'social_account_key'),
        ]);

        return [
            'bounds' => $bounds ?? $this->bounds->execute($workspace, $accountKeys, $range->timezone),
            'range' => $range->toArray(),
            'previous_range' => $previous->toArray(),
            'summary' => [
                'posts' => MetricComparison::between(data_get($current, 'posts'), data_get($prior, 'posts')),
                'followers' => [
                    'value' => $currentFollowers,
                    'previous' => $previousFollowers,
                    'change' => match (true) {
                        $currentFollowers === null => null,
                        $previousFollowers !== null => $currentFollowers - $previousFollowers,
                        default => $currentNet,
                    },
                ],
                'net_followers' => MetricComparison::between($currentNet, data_get($followers, 'previous_net')),
                'reactions' => MetricComparison::between(data_get($current, 'reactions'), data_get($prior, 'reactions')),
                'comments' => MetricComparison::between(data_get($current, 'comments'), data_get($prior, 'comments')),
                'engagement_rate' => MetricComparison::between(data_get($current, 'engagement_rate'), data_get($prior, 'engagement_rate')),
                'views' => MetricComparison::between(data_get($current, 'views'), data_get($prior, 'views')),
                'impressions' => MetricComparison::between(data_get($current, 'impressions'), data_get($prior, 'impressions')),
                'clicks' => MetricComparison::between(data_get($current, 'clicks'), data_get($prior, 'clicks')),
                'reposts' => MetricComparison::between(data_get($current, 'reposts'), data_get($prior, 'reposts')),
                'quotes' => MetricComparison::between(data_get($current, 'quotes'), data_get($prior, 'quotes')),
                'reach' => MetricComparison::between(data_get($current, 'reach'), data_get($prior, 'reach')),
                'shares' => MetricComparison::between(data_get($current, 'shares'), data_get($prior, 'shares')),
                'saves' => MetricComparison::between(data_get($current, 'saves'), data_get($prior, 'saves')),
                'watch_time_minutes' => MetricComparison::between(data_get($current, 'watch_time_minutes'), data_get($prior, 'watch_time_minutes')),
                'average_watch_time_seconds' => MetricComparison::between(data_get($current, 'average_watch_time_seconds'), data_get($prior, 'average_watch_time_seconds')),
                'follows_gained' => MetricComparison::between(data_get($current, 'follows_gained'), data_get($prior, 'follows_gained')),
            ],
            'followers' => [
                ...data_get($followers, 'followers'),
                'accounts' => $this->withLiveAccount($followerAccounts, $liveAccounts),
            ],
            'posts' => [
                ...data_get($publications, 'posts'),
                'accounts' => $this->withLiveAccount($postAccounts, $liveAccounts),
            ],
            'top_posts' => [
                'reactions' => $this->withLiveAccount(data_get($topPosts, 'reactions'), $liveAccounts),
                'comments' => $this->withLiveAccount(data_get($topPosts, 'comments'), $liveAccounts),
            ],
            'performance' => $this->withLiveAccount($performance, $liveAccounts),
            'coverage' => $this->coverage($workspace, $channelKeys === null ? null : array_keys($channelKeys)),
        ];
    }

    /**
     * The live connection status and current avatar of each key that is still a
     * social account of the workspace. A key whose account is gone is absent, so
     * its row keeps no status, and a row without a live avatar keeps the one its
     * analytics rows stored.
     *
     * @param  list<string>  $keys
     * @return array<string, array{status: string, avatar_url: string|null}>
     */
    private function liveAccountsByKey(Workspace $workspace, array $keys): array
    {
        $ids = array_values(array_unique(array_filter($keys, fn (string $key): bool => Str::isUuid($key))));

        return SocialAccount::query()
            ->whereBelongsTo($workspace)
            ->whereIn('id', $ids)
            ->get(['id', 'status', 'avatar_url'])
            ->mapWithKeys(fn (SocialAccount $account): array => [$account->id => [
                'status' => $account->status->value,
                'avatar_url' => $account->avatar_url,
            ]])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{status: string, avatar_url: string|null}>  $liveAccounts
     * @return list<array<string, mixed>>
     */
    private function withLiveAccount(array $rows, array $liveAccounts): array
    {
        return array_map(function (array $row) use ($liveAccounts): array {
            $live = data_get($liveAccounts, data_get($row, 'social_account_key'));

            return [
                ...$row,
                'avatar_url' => data_get($live, 'avatar_url') ?? data_get($row, 'avatar_url'),
                'status' => data_get($live, 'status'),
            ];
        }, $rows);
    }

    /**
     * @param  array<string, string>|null  $channelKeys
     * @return list<string>|null
     */
    public function keys(?array $channelKeys): ?array
    {
        return $channelKeys === null ? null : array_values(array_unique($channelKeys));
    }

    /**
     * @param  list<string>|null  $accountIds
     * @return list<array<string, mixed>>
     */
    private function coverage(Workspace $workspace, ?array $accountIds): array
    {
        return AnalyticsSyncState::query()
            ->when($accountIds !== null, fn (Builder $states): Builder => $states->whereIn('social_account_id', $accountIds))
            ->whereHas('socialAccount', fn (Builder $accounts): Builder => $accounts
                ->whereBelongsTo($workspace)
                ->connected()
                ->includedInAnalytics())
            ->select([
                'social_account_id', 'collector', 'status',
                'target_since', 'oldest_reached_at', 'high_watermark_at',
                'last_success_at', 'last_error_category',
            ])
            ->get()
            ->toArray();
    }
}
