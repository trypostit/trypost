<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Http\Requests\AnalyticsReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(AnalyticsReportRequest $request, BuildWorkspaceAnalyticsReport $analytics): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        return response()->json($analytics->forSelection($workspace, $request->validated()));
    }

    public function showPublication(Request $request, string $publication, ReadPublicationAnalytics $analytics): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        return response()->json($analytics->latestForWorkspacePublication($workspace, $publication));
    }
}
