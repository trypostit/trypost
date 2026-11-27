<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildPublishingStats;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\PostStatsRequest;
use App\Support\Admin\AdminNetworks;
use Inertia\Inertia;
use Inertia\Response;

class PostStatsController extends Controller
{
    public function index(PostStatsRequest $request): Response
    {
        $dates = $request->dates();

        return Inertia::render('admin/posts/Index', [
            'stats' => BuildPublishingStats::execute($dates['start'], $dates['end'], $request->timezone(), $request->platforms()),
            'networks' => AdminNetworks::options(),
            'range' => $dates,
            'bounds' => [
                'min' => PostStatsRequest::earliest($request->timezone())->toDateString(),
                'max' => now($request->timezone())->toDateString(),
            ],
            'filters' => [
                'range' => $request->range(),
                'start' => $dates['start'],
                'end' => $dates['end'],
                'networks' => $request->networks(),
            ],
        ]);
    }
}
