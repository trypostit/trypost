<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildPublishingStats;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\IndexPostStatsRequest;
use App\Support\Admin\AdminNetworks;
use Inertia\Inertia;
use Inertia\Response;

class PostStatsController extends Controller
{
    public function index(IndexPostStatsRequest $request): Response
    {
        return Inertia::render('admin/posts/Index', [
            'stats' => BuildPublishingStats::execute($request->dateRange(), $request->platforms()),
            'networks' => AdminNetworks::options(),
            ...$request->rangeProps(),
            'filters' => [
                ...$request->rangeFilters(),
                'networks' => $request->networks(),
            ],
        ]);
    }
}
