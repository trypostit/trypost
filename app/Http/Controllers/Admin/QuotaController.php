<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildQuotaCards;
use App\Enums\Analytics\DateRangePreset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Quota\IndexQuotaRequest;
use Inertia\Inertia;
use Inertia\Response;

class QuotaController extends Controller
{
    public function index(IndexQuotaRequest $request): Response
    {
        return Inertia::render('admin/quotas/Index', [
            'cards' => BuildQuotaCards::execute($request->dateRange()),
            'ranges' => DateRangePreset::backoffice(),
            ...$request->rangeProps(),
            'filters' => $request->rangeFilters(),
        ]);
    }
}
