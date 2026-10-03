<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\RequestIds;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class ResolveAnalyticsLabelFilter
{
    /**
     * @param  SupportCollection<int, mixed>  $requested
     * @return array{labels: Collection<int, WorkspaceLabel>, selected: list<string>}
     */
    public function execute(Workspace $workspace, SupportCollection $requested): array
    {
        $labels = $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']);

        return [
            'labels' => $labels,
            'selected' => RequestIds::selected($requested, $labels->pluck('id')),
        ];
    }
}
