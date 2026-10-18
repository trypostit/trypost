<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SavedReply\CreateSavedReply;
use App\Actions\SavedReply\DeleteSavedReply;
use App\Actions\SavedReply\ListSavedReplies;
use App\Actions\SavedReply\UpdateSavedReply;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\Api\SavedReply\StoreSavedReplyRequest;
use App\Http\Requests\Api\SavedReply\UpdateSavedReplyRequest;
use App\Http\Resources\Api\SavedReplyResource;
use App\Models\WorkspaceSavedReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class SavedReplyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(Platform::inboxModuleEnabled(), Response::HTTP_NOT_FOUND);

        $workspace = $request->user()->currentWorkspace;

        Gate::authorize('publishDirectly', $workspace);

        $search = $request->string('search')->trim()->toString();

        return SavedReplyResource::collection(
            ListSavedReplies::execute($workspace, $search)->paginate((int) config('app.pagination.default'))
        );
    }

    public function store(StoreSavedReplyRequest $request): JsonResponse
    {
        $savedReply = CreateSavedReply::execute($request->user()->currentWorkspace, $request->validated());

        return (new SavedReplyResource($savedReply))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateSavedReplyRequest $request, WorkspaceSavedReply $savedReply): SavedReplyResource
    {
        return new SavedReplyResource(UpdateSavedReply::execute($savedReply, $request->validated()));
    }

    public function destroy(Request $request, WorkspaceSavedReply $savedReply): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        abort_unless(Platform::inboxModuleEnabled() && $savedReply->workspace_id === $workspace?->id, Response::HTTP_NOT_FOUND);

        Gate::authorize('publishDirectly', $workspace);

        DeleteSavedReply::execute($savedReply);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
