<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\App\Concerns\TracksWorkspaceLimitHit;
use App\Http\Controllers\Controller;
use App\Http\Resources\App\WorkspaceCreationCheckResource;
use App\Models\Workspace;
use Illuminate\Http\Request;

class WorkspaceCreationCheckController extends Controller
{
    use TracksWorkspaceLimitHit;

    public function store(Request $request): WorkspaceCreationCheckResource
    {
        $this->authorize('create', Workspace::class);

        $user = $request->user();
        $allowed = config('trypost.self_hosted') || $user->account?->canCreateWorkspace() !== false;

        if (! $allowed) {
            $this->trackLimitHit($user);
        }

        return new WorkspaceCreationCheckResource($allowed);
    }
}
