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
        $dates = $request->dates();

        return Inertia::render('admin/billing/insights/Index', [
            'insights' => BuildSignupInsights::execute($dates['start'], $dates['end'], $request->timezone()),
            'range' => $dates,
            'ranges' => IndexSignupInsightsRequest::RANGES,
            'bounds' => [
                'min' => IndexSignupInsightsRequest::earliest($request->timezone())->toDateString(),
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
