<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Models\AnalyticsSyncState;
use App\Models\Workspace;
use App\Support\Analytics\MetricComparison;
use Illuminate\Database\Eloquent\Builder;

class BuildWorkspaceAnalyticsReport
{
    public function __construct(
        private readonly BuildPublicationAnalyticsReport $publications,
        private readonly BuildFollowerAnalyticsReport $followers,
        private readonly GetAnalyticsBounds $bounds,
    ) {}

    /**
     * @param  array{min: ?string, max: ?string}|null  $bounds
     * @return array<string, mixed>
     */
    public function execute(Workspace $workspace, DateRange $range, ?array $bounds = null): array
    {
        $previous = $range->previous();
        $publications = $this->publications->execute($workspace, $previous, $range);
        $followers = $this->followers->execute($workspace, $previous, $range);
        $current = data_get($publications, 'current_totals');
        $prior = data_get($publications, 'previous_totals');
        $currentFollowers = data_get($followers, 'current_total');
        $previousFollowers = data_get($followers, 'previous_total');

        return [
            'bounds' => $bounds ?? $this->bounds->execute($workspace),
            'range' => $range->toArray(),
            'previous_range' => $previous->toArray(),
            'summary' => [
                'posts' => MetricComparison::between(data_get($current, 'posts'), data_get($prior, 'posts')),
                'followers' => [
                    'value' => $currentFollowers,
                    'previous' => $previousFollowers,
                    'change' => $currentFollowers !== null && $previousFollowers !== null
                        ? $currentFollowers - $previousFollowers : null,
                ],
                'reactions' => MetricComparison::between(data_get($current, 'reactions'), data_get($prior, 'reactions')),
                'comments' => MetricComparison::between(data_get($current, 'comments'), data_get($prior, 'comments')),
                'engagement_rate' => MetricComparison::between(data_get($current, 'engagement_rate'), data_get($prior, 'engagement_rate')),
            ],
            'followers' => data_get($followers, 'followers'),
            'posts' => data_get($publications, 'posts'),
            'top_posts' => data_get($publications, 'top_posts'),
            'performance' => data_get($publications, 'performance'),
            'coverage' => $this->coverage($workspace),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function coverage(Workspace $workspace): array
    {
        return AnalyticsSyncState::query()
            ->whereHas('socialAccount', fn (Builder $accounts): Builder => $accounts
                ->whereBelongsTo($workspace)
                ->connected()
                ->active()
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
