<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\App\WorkspaceCreationCheckResource;
use App\Jobs\PostHog\TrackWorkspaceLimitHit;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Http\Request;

class WorkspaceCreationCheckController extends Controller
{
    public function store(Request $request): WorkspaceCreationCheckResource
    {
        $this->authorize('create', Workspace::class);

        $user = $request->user();
        $allowed = config('trypost.self_hosted') || $user->account?->canCreateWorkspace() !== false;

        rescue(function () use ($user, $allowed): void {
            TrackWorkspaceLimitHit::dispatchIf(PostHogService::isEnabled() && ! $allowed, (string) $user->id);
        });

        return new WorkspaceCreationCheckResource($allowed);
    }
}
