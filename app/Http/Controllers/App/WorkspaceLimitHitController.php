<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\PostHog\TrackWorkspaceLimitHit;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceLimitHitController extends Controller
{
    public function store(Request $request): Response
    {
        $this->authorize('create', Workspace::class);

        $this->trackLimitHit($request->user());

        return response()->noContent();
    }

    private function trackLimitHit(User $user): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        rescue(function () use ($user): void {
            TrackWorkspaceLimitHit::dispatch((string) $user->id);
        });
    }
}
