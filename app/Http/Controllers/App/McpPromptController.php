<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Mcp\PromptAnswer;
use App\Http\Requests\App\Mcp\StoreMcpPromptRequest;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Services\PostHogService;
use Illuminate\Http\Response;

class McpPromptController extends Controller
{
    /**
     * Records what the user did with the card inviting them to connect an AI.
     * The card hides itself in the browser; this only tracks the answer.
     */
    public function store(StoreMcpPromptRequest $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $answer = $request->enum('answer', PromptAnswer::class);

        rescue(function () use ($user, $workspace, $answer): void {
            CaptureAccountEvent::dispatchIf(
                PostHogService::isEnabled() && filled($workspace),
                (string) $user->id,
                (string) $workspace?->account_id,
                $answer->event(),
                ['workspace_id' => (string) $workspace?->id],
            );
        });

        return response()->noContent();
    }
}
