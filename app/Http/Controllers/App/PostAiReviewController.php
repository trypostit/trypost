<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Ai\Agents\PostContentReviewer;
use App\Http\Requests\App\Ai\ReviewPostContentRequest;
use App\Models\Post;
use App\Services\Ai\RecordAiUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PostAiReviewController extends Controller
{
    public function review(ReviewPostContentRequest $request, Post $post): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $post);

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $agent = new PostContentReviewer(workspace: $workspace);
        $result = $agent->prompt($request->string('content')->toString());

        RecordAiUsage::recordText(
            workspace: $workspace,
            promptTokens: $result->usage->promptTokens,
            completionTokens: $result->usage->completionTokens,
            provider: (string) $result->meta->provider,
            model: (string) $result->meta->model,
            userId: $request->user()->id,
            postId: $post->id,
            metadata: ['agent' => 'post_reviewer'],
        );

        return response()->json([
            'suggestions' => data_get($result, 'suggestions', []),
        ]);
    }
}
