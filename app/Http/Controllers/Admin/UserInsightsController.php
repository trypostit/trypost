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
        $dates = $request->dates();

        return Inertia::render('admin/users/Insights', [
            'usage' => BuildUsageInsights::execute($dates['start'], $dates['end'], $request->timezone()),
            'churn' => BuildChurnRisk::execute($dates['start'], $dates['end'], $request->timezone()),
            'range' => $dates,
            'ranges' => IndexUserInsightsRequest::RANGES,
            'bounds' => [
                'min' => IndexUserInsightsRequest::earliest($request->timezone())->toDateString(),
                'max' => now($request->timezone())->toDateString(),
            ],
            'filters' => [
                'range' => $request->range(),
                'start' => $dates['start'],
                'end' => $dates['end'],
            ],
        ]);
    }
}
