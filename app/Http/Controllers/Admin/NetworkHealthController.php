<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildChannelDistribution;
use App\Actions\Admin\BuildChannelGrowth;
use App\Actions\Admin\BuildNetworkHealth;
use App\Actions\Admin\BuildNetworkPermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Network\IndexNetworkHealthRequest;
use Inertia\Inertia;
use Inertia\Response;

class NetworkHealthController extends Controller
{
    public function index(IndexNetworkHealthRequest $request): Response
    {
        return Inertia::render('admin/networks/Index', [
            'networks' => BuildNetworkHealth::execute(),
            'distribution' => BuildChannelDistribution::execute(),
            'permissions' => BuildNetworkPermissions::execute(),
            'growth' => BuildChannelGrowth::execute($request->dateRange()),
            ...$request->rangeProps(),
            'filters' => $request->rangeFilters(),
        ]);
    }
}
