<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildSignupInsights;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Insights\IndexSignupInsightsRequest;
use Inertia\Inertia;
use Inertia\Response;

class SignupInsightsController extends Controller
{
    public function index(IndexSignupInsightsRequest $request): Response
    {
        return Inertia::render('admin/billing/insights/Index', [
            'insights' => BuildSignupInsights::execute($request->dateRange()),
            'ranges' => IndexSignupInsightsRequest::RANGES,
            ...$request->rangeProps(),
            'filters' => $request->rangeFilters(),
        ]);
    }
}
