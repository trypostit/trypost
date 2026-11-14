<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Actions\Analytics\ResolveAnalyticsChannelFilter;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Dto\Analytics\PublicationFilter;
use App\Http\Requests\Api\Analytics\ShowAnalyticsReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(
        ShowAnalyticsReportRequest $request,
        BuildWorkspaceAnalyticsReport $analytics,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsChannelFilter $channelFilter,
    ): JsonResponse {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        ['selection' => $selection, 'clamped' => $clamped] = $presets->selection($request->validated(), $request->user()->timezone);
        ['keys' => $channelKeys] = $channelFilter->execute($workspace, $request->collect('channels'));

        return response()->json($analytics->forSelection(
            $workspace,
            $selection,
            $channelKeys,
            $clamped,
            weekStart: $request->user()->week_starts_on,
            filter: PublicationFilter::labelsFrom($request->safe()),
        ));
    }

    public function showPublication(Request $request, string $publication, ReadPublicationAnalytics $analytics): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        return response()->json($analytics->latestForWorkspacePublication($workspace, $publication));
    }
}
