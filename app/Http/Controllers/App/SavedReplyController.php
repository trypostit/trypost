<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\SavedReply\CreateSavedReply;
use App\Actions\SavedReply\DeleteSavedReply;
use App\Actions\SavedReply\ListSavedReplies;
use App\Actions\SavedReply\UpdateSavedReply;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\SavedReply\StoreSavedReplyRequest;
use App\Http\Requests\App\SavedReply\UpdateSavedReplyRequest;
use App\Http\Resources\Api\SavedReplyResource;
use App\Models\WorkspaceSavedReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SavedReplyController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Platform::inboxModuleEnabled(), HttpResponse::HTTP_NOT_FOUND);

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('publishDirectly', $workspace);

        $search = $request->string('search')->trim()->toString();

        return Inertia::render('saved-replies/Index', [
            'savedReplies' => Inertia::scroll(fn () => SavedReplyResource::collection(
                ListSavedReplies::execute($workspace, $search)->paginate((int) config('app.pagination.default'))
            )),
            'hasData' => $workspace->savedReplies()->exists(),
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function store(StoreSavedReplyRequest $request): RedirectResponse|JsonResponse
    {
        $savedReply = CreateSavedReply::execute($request->user()->currentWorkspace, $request->validated());

        if ($request->expectsJson()) {
            return (new SavedReplyResource($savedReply))
                ->response()
                ->setStatusCode(HttpResponse::HTTP_CREATED);
        }

        return back();
    }

    public function update(UpdateSavedReplyRequest $request, WorkspaceSavedReply $savedReply): RedirectResponse|JsonResponse
    {
        $savedReply = UpdateSavedReply::execute($savedReply, $request->validated());

        if ($request->expectsJson()) {
            return (new SavedReplyResource($savedReply))->response();
        }

        return back();
    }

    public function destroy(Request $request, WorkspaceSavedReply $savedReply): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        abort_unless(Platform::inboxModuleEnabled() && $savedReply->workspace_id === $workspace?->id, HttpResponse::HTTP_NOT_FOUND);

        $this->authorize('publishDirectly', $workspace);

        DeleteSavedReply::execute($savedReply);

        return back();
    }
}
