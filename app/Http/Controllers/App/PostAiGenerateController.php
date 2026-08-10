<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\Ai\GeneratePostContentRequest;
use App\Jobs\Ai\StreamPostContent;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PostAiGenerateController extends Controller
{
    public function generate(GeneratePostContentRequest $request, Post $post): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $post);

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $generationId = $request->string('generation_id')->toString();

        StreamPostContent::dispatch(
            workspaceId: $workspace->id,
            userId: $request->user()->id,
            generationId: $generationId,
            prompt: $request->string('prompt')->toString(),
            currentContent: $request->input('current_content'),
        );

        return response()->json([
            'generation_id' => $generationId,
            'channel' => "user.{$request->user()->id}.ai-gen.{$generationId}",
        ], Response::HTTP_ACCEPTED);
    }
}
