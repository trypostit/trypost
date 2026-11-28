<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\SocialAccount\BuildChannelDistribution;
use App\Actions\SocialAccount\BuildChannelGrowth;
use App\Actions\SocialAccount\BuildNetworkHealth;
use App\Actions\SocialAccount\BuildNetworkPermissions;
use App\Enums\Analytics\DateRangePreset;
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
            'ranges' => DateRangePreset::admin(),
            ...$request->rangeProps(),
            'filters' => $request->rangeFilters(),
        ]);
    }
}
