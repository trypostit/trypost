<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Models\Workspace;
use App\Support\Analytics\MetricComparison;
use Illuminate\Support\Facades\DB;

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
        $current = $publications['current_totals'];
        $prior = $publications['previous_totals'];
        $currentFollowers = $followers['current_total'];
        $previousFollowers = $followers['previous_total'];

        return [
            'bounds' => $bounds ?? $this->bounds->execute($workspace),
            'range' => $range->toArray(),
            'previous_range' => $previous->toArray(),
            'summary' => [
                'posts' => MetricComparison::between($current['posts'], $prior['posts']),
                'followers' => [
                    'value' => $currentFollowers,
                    'previous' => $previousFollowers,
                    'change' => $currentFollowers !== null && $previousFollowers !== null
                        ? $currentFollowers - $previousFollowers : null,
                ],
                'reactions' => MetricComparison::between($current['reactions'], $prior['reactions']),
                'comments' => MetricComparison::between($current['comments'], $prior['comments']),
                'engagement_rate' => MetricComparison::between($current['engagement_rate'], $prior['engagement_rate']),
            ],
            'followers' => $followers['followers'],
            'posts' => $publications['posts'],
            'top_posts' => $publications['top_posts'],
            'performance' => $publications['performance'],
            'coverage' => $this->coverage($workspace),
        ];
    }

    /** @return list<object> */
    private function coverage(Workspace $workspace): array
    {
        return DB::table('analytics_sync_states as state')
            ->join('social_accounts as account', 'account.id', '=', 'state.social_account_id')
            ->where('account.workspace_id', $workspace->id)
            ->whereIn('account.platform', Platform::analyticsValues())
            ->select([
                'state.social_account_id', 'state.collector', 'state.status',
                'state.target_since', 'state.oldest_reached_at', 'state.high_watermark_at',
                'state.last_success_at', 'state.last_error_category',
            ])
            ->get()
            ->all();
    }
}
