<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\AccessToken\HasConnectedMcpClient;
use App\Enums\Mcp\PromptAnswer;
use App\Http\Middleware\App\HandleInertiaRequests;
use App\Http\Requests\App\Mcp\StoreMcpPromptRequest;
use App\Http\Resources\App\McpConnectionStatusResource;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Services\PostHogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class McpPromptController extends Controller
{
    /**
     * Whether the AI the user is connecting from the card has connected to
     * the current workspace; once it has, the card is no longer shared.
     */
    public function show(Request $request): McpConnectionStatusResource
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $connected = filled($workspace) && HasConnectedMcpClient::execute($user, $workspace);

        if ($connected) {
            Cache::forget(HandleInertiaRequests::mcpPromptCacheKey($user, $workspace));
        }

        return new McpConnectionStatusResource($connected);
    }

    /**
     * Records what the user did with the card inviting them to connect an AI.
     * The card hides itself in the browser; this only tracks the answer.
     */
    public function store(StoreMcpPromptRequest $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $answer = $request->enum('answer', PromptAnswer::class);
        $properties = Arr::whereNotNull([
            'workspace_id' => $workspace?->id,
            'client' => $request->validated('client'),
        ]);

        rescue(function () use ($user, $workspace, $answer, $properties): void {
            CaptureAccountEvent::dispatchIf(
                PostHogService::isEnabled() && filled($workspace),
                (string) $user->id,
                (string) $workspace?->account_id,
                $answer->event(),
                $properties,
            );
        });

        return response()->noContent();
    }
}
