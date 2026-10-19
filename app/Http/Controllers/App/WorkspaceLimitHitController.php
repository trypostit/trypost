<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Workspace\TrackWorkspaceLimitHit;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceLimitHitController extends Controller
{
    public function store(Request $request): Response
    {
        $this->authorize('create', Workspace::class);

        TrackWorkspaceLimitHit::execute($request->user());

        return response()->noContent();
    }
}
