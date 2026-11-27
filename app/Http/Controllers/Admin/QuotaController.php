<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildQuotaCards;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Quota\IndexQuotaRequest;
use Inertia\Inertia;
use Inertia\Response;

class QuotaController extends Controller
{
    public function index(IndexQuotaRequest $request): Response
    {
        $dates = $request->dates();

        return Inertia::render('admin/quotas/Index', [
            'cards' => BuildQuotaCards::execute($dates['start'], $dates['end']),
            'range' => $dates,
            'ranges' => IndexQuotaRequest::RANGES,
            'filters' => [
                'range' => $request->range(),
                'start' => $dates['start'],
                'end' => $dates['end'],
            ],
        ]);
    }
}
