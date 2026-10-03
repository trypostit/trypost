<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Idea\GenerateIdeas;
use App\Http\Requests\App\Idea\GenerateIdeasRequest;
use App\Http\Resources\App\IdeaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class IdeaGenerateController extends Controller
{
    public function __invoke(GenerateIdeasRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $ideas = GenerateIdeas::execute(
            workspace: $workspace,
            user: $request->user(),
            stageId: $request->validated('idea_stage_id'),
            count: (int) $request->validated('count'),
            business: $request->validated('business'),
            audience: $request->validated('audience'),
            notes: $request->validated('notes'),
        );

        return IdeaResource::collection($ideas)->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
