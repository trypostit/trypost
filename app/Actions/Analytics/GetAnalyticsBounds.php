<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\SocialAccount\Platform;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GetAnalyticsBounds
{
    /** @return array{min: ?string, max: ?string} */
    public function execute(Workspace $workspace): array
    {
        $accounts = DB::table('analytics_account_daily_snapshots')
            ->where('workspace_id', $workspace->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->selectRaw('MIN(snapshot_date) as earliest, MAX(snapshot_date) as latest')
            ->first();
        $publications = DB::table('analytics_publications')
            ->where('workspace_id', $workspace->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->selectRaw('MIN(provider_published_at) as earliest, MAX(provider_published_at) as latest')
            ->first();
        $minimum = array_filter([$accounts?->earliest, $publications?->earliest]);
        $maximum = array_filter([$accounts?->latest, $publications?->latest]);

        return [
            'min' => $minimum ? CarbonImmutable::parse(min($minimum), 'UTC')->toDateString() : null,
            'max' => $maximum ? CarbonImmutable::parse(max($maximum), 'UTC')->toDateString() : null,
        ];
    }
}
