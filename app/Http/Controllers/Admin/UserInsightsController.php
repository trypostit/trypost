<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Users\BuildChurnRisk;
use App\Actions\Admin\Users\BuildUsageInsights;
use App\Actions\Admin\Users\CountAccountActivity;
use App\Enums\Analytics\DateRangePreset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\IndexUserInsightsRequest;
use Inertia\Inertia;
use Inertia\Response;

class UserInsightsController extends Controller
{
    public function index(IndexUserInsightsRequest $request): Response
    {
        $activity = CountAccountActivity::execute($request->dateRange());

        return Inertia::render('admin/users/Insights', [
            'usage' => BuildUsageInsights::execute($activity),
            'churn' => BuildChurnRisk::execute($activity),
            'ranges' => DateRangePreset::admin(),
            ...$request->rangeProps(),
            'filters' => $request->rangeFilters(),
        ]);
    }
}
