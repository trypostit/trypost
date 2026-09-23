<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsPublication;
use App\Queries\Analytics\PublicationAnalyticsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsPublicationController extends Controller
{
    public function show(Request $request, string $publication, PublicationAnalyticsQuery $analytics): Response
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        $record = AnalyticsPublication::query()
            ->available()
            ->where('workspace_id', $workspace->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->findOrFail($publication);

        return Inertia::render('analytics/Publications/Show', [
            'detail' => $analytics->latestForPublication($record),
        ]);
    }
}
