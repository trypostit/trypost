<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\Ai\StartPostCreationRequest;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class PostAiCreateController extends Controller
{
    public function start(StartPostCreationRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $socialAccountId = $request->input('social_account_id');

        if ($socialAccountId) {
            $owned = SocialAccount::where('id', $socialAccountId)
                ->where('workspace_id', $workspace->id)
                ->exists();

            if (! $owned) {
                abort(Response::HTTP_FORBIDDEN);
            }
        }

        $creationId = (string) Str::uuid();

        StreamPostCreation::dispatch(
            userId: $request->user()->id,
            creationId: $creationId,
            workspaceId: $workspace->id,
            format: $request->string('format')->toString(),
            socialAccountId: $socialAccountId,
            imageCount: (int) $request->input('image_count', 0),
            prompt: $request->string('prompt')->toString(),
            date: $request->input('date'),
            template: $request->input('template', 'image_card'),
            applyBrandVisuals: $request->boolean('apply_brand_visuals', true),
        );

        return response()->json([
            'creation_id' => $creationId,
            'channel' => "user.{$request->user()->id}.ai-creation.{$creationId}",
        ], Response::HTTP_ACCEPTED);
    }

    public function loading(Request $request, string $creationId): InertiaResponse
    {
        return Inertia::render('posts/ai/Loading', [
            'creationId' => $creationId,
            'channel' => "user.{$request->user()->id}.ai-creation.{$creationId}",
            'imageCount' => (int) $request->query('images', '0'),
            'format' => (string) $request->query('format', ''),
            'prompt' => (string) $request->query('prompt', ''),
        ]);
    }
}
