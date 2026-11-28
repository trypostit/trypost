<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Dashboard\BuildAdminDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/Index', [
            'dashboard' => BuildAdminDashboard::execute((string) $request->user()->timezone),
        ]);
    }
}
