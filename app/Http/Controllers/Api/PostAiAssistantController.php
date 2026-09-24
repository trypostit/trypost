<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Ai\AssistPostContent;
use App\Enums\Ai\PostAssistantMode;
use App\Http\Requests\App\Ai\AssistPostContentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
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

        return response()->json([
            'content' => AssistPostContent::execute(
                workspace: $workspace,
                user: $request->user(),
                mode: PostAssistantMode::from($request->string('mode')->toString()),
                currentContent: $request->string('current_content')->toString(),
                prompt: $request->input('prompt'),
            ),
        ]);
    }
}
