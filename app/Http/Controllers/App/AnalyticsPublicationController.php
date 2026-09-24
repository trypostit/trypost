<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsPublicationController extends Controller
{
    public function show(Request $request, string $publication, ReadPublicationAnalytics $analytics): Response
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        return Inertia::render('analytics/Publications/Show', [
            'detail' => $analytics->latestForWorkspacePublication($workspace, $publication),
        ]);
    }
}
