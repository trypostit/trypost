<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Queries\Analytics\PublicationAnalyticsQuery;
use App\Queries\Analytics\WorkspaceAnalyticsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function show(Request $request, string $post, PublicationAnalyticsQuery $analytics): Response
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        $record = Post::query()->whereBelongsTo($workspace)->findOrFail($post);
        $publication = AnalyticsPublication::query()
            ->available()
            ->whereBelongsTo($workspace)
            ->whereIn('platform', Platform::analyticsValues())
            ->whereHas('postPlatform', fn (Builder $query): Builder => $query->whereBelongsTo($record));

        if ($request->query('publication')) {
            $publication->whereKey($request->query('publication'));
        }

        return Inertia::render('analytics/Publications/Show', [
            'detail' => $analytics->latestForPublication($publication->orderByDesc('provider_published_at')->firstOrFail()),
        ]);
    }

    public function index(Request $request, WorkspaceAnalyticsQuery $analytics): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('view', $workspace);

        $validated = $request->validate([
            'start' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'end' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);
        $bounds = $analytics->boundsFor($workspace);
        $today = CarbonImmutable::today('UTC');
        $end = $bounds['max'] ? CarbonImmutable::parse($bounds['max'], 'UTC') : $today;
        $start = $end->subDays(29);

        if ($bounds['min'] !== null && isset($validated['start'])) {
            $start = CarbonImmutable::parse($validated['start'], 'UTC');
        }

        if ($bounds['max'] !== null && isset($validated['end'])) {
            $end = CarbonImmutable::parse($validated['end'], 'UTC');
        }

        if ($bounds['min'] !== null && $bounds['max'] !== null) {
            $minimum = CarbonImmutable::parse($bounds['min'], 'UTC');
            $maximum = CarbonImmutable::parse($bounds['max'], 'UTC');
            $start = $start->lessThan($minimum) ? $minimum : ($start->greaterThan($maximum) ? $maximum : $start);
            $end = $end->lessThan($minimum) ? $minimum : ($end->greaterThan($maximum) ? $maximum : $end);
        }

        if ($start->greaterThan($end)) {
            $start = $end;
        }

        return Inertia::render('analytics/Index', [
            'report' => $analytics->for($workspace, new DateRange($start, $end)),
        ]);
    }
}
