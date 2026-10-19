<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Ai\AssistPostContent;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\PostHog\AiEvent;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\Ai\AssistPostContentRequest;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Exceptions\AiException;
use Symfony\Component\HttpFoundation\Response;

class PostAiAssistantController extends Controller
{
    public function __invoke(AssistPostContentRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $mode = PostAssistantMode::from($request->string('mode')->toString());

        try {
            $content = AssistPostContent::execute(
                workspace: $workspace,
                user: $request->user(),
                mode: $mode,
                currentContent: $request->string('current_content')->toString(),
                prompt: $request->input('prompt'),
                previousContent: $request->input('previous_content'),
                platform: $request->enum('platform', Platform::class),
                account: $request->filled('social_account_id')
                    ? $workspace->socialAccounts()->find($request->input('social_account_id'))
                    : null,
            );
        } catch (AiException|RequestException|ConnectionException) {
            return response()->json(['message' => __('posts.composer.assistant_error')], Response::HTTP_BAD_GATEWAY);
        }

        rescue(fn () => $this->trackAssistUsed($request->user(), $workspace, $mode));

        return response()->json(['content' => $content]);
    }

    private function trackAssistUsed(User $user, Workspace $workspace, PostAssistantMode $mode): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        CaptureAccountEvent::dispatch(
            (string) $user->id,
            (string) $workspace->account_id,
            AiEvent::AssistUsed->value,
            [
                'mode' => $mode->value,
                'workspace_id' => (string) $workspace->id,
            ],
        )->afterCommit();
    }
}
