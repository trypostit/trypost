<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsReportRequest;
use App\Http\Requests\App\Analytics\ShowAnalyticsRequest;
use App\Models\Post;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function show(ShowAnalyticsRequest $request, string $post, ReadPublicationAnalytics $analytics): Response
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        $record = Post::query()->whereBelongsTo($workspace)->findOrFail($post);

        return Inertia::render('analytics/Publications/Show', [
            'detail' => $analytics->latestForPostPublication($record, $request->validated('publication')),
        ]);
    }

    public function index(
        AnalyticsReportRequest $request,
        BuildWorkspaceAnalyticsReport $analytics,
    ): Response {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        return Inertia::render('analytics/Index', [
            'report' => $analytics->forSelection($workspace, $request->validated()),
        ]);
    }
}
