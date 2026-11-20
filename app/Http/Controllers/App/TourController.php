<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Tour\Outcome;
use App\Http\Requests\App\Tour\StoreTourRequest;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Services\PostHogService;
use Illuminate\Http\Response;

class TourController extends Controller
{
    /**
     * Records how a tour ended and at which step. The browser keeps which
     * tours the user went through; this only tracks it.
     */
    public function store(StoreTourRequest $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $outcome = $request->enum('outcome', Outcome::class);
        $properties = [
            'tour' => $request->validated('tour'),
            'step' => (int) $request->validated('step'),
            'steps' => (int) $request->validated('steps'),
            'workspace_id' => $workspace->id,
        ];

        rescue(function () use ($user, $workspace, $outcome, $properties): void {
            CaptureAccountEvent::dispatchIf(
                PostHogService::isEnabled(),
                (string) $user->id,
                (string) $workspace->account_id,
                $outcome->event(),
                $properties,
            );
        });

        return response()->noContent();
    }
}
