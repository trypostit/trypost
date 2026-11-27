<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Users\BuildChurnRisk;
use App\Actions\Admin\Users\BuildUsageInsights;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\IndexUserInsightsRequest;
use Inertia\Inertia;
use Inertia\Response;

class UserInsightsController extends Controller
{
    public function index(IndexUserInsightsRequest $request): Response
    {
        $range = $request->dateRange();

        return Inertia::render('admin/users/Insights', [
            'usage' => BuildUsageInsights::execute($range),
            'churn' => BuildChurnRisk::execute($range),
            'ranges' => IndexUserInsightsRequest::RANGES,
            ...$request->rangeProps(),
            'filters' => $request->rangeFilters(),
        ]);
    }
}
