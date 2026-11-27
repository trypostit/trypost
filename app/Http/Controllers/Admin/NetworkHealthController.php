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
        $dates = $request->dates();

        return Inertia::render('admin/networks/Index', [
            'networks' => BuildNetworkHealth::execute(),
            'distribution' => BuildChannelDistribution::execute(),
            'permissions' => BuildNetworkPermissions::execute(),
            'growth' => BuildChannelGrowth::execute($dates['start'], $dates['end'], $request->timezone()),
            'range' => $dates,
            'bounds' => [
                'min' => IndexNetworkHealthRequest::earliest($request->timezone())->toDateString(),
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
