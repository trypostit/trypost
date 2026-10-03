<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Idea\BuildIdeasPageProps;
use App\Actions\Idea\CreateIdea;
use App\Actions\Idea\DeleteIdeas;
use App\Actions\Idea\DuplicateIdea;
use App\Actions\Idea\MoveIdea;
use App\Actions\Idea\UpdateIdea;
use App\Http\Requests\App\Idea\BulkDestroyIdeasRequest;
use App\Http\Requests\App\Idea\ListIdeasRequest;
use App\Http\Requests\App\Idea\MoveIdeaRequest;
use App\Http\Requests\App\Idea\StoreIdeaRequest;
use App\Http\Requests\App\Idea\UpdateIdeaRequest;
use App\Http\Resources\App\IdeaResource;
use App\Models\Idea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdeaController extends Controller
{
    public function index(ListIdeasRequest $request): Response
    {
        return $this->page($request, null);
    }

    public function create(ListIdeasRequest $request): Response
    {
        $workspace = $request->user()->currentWorkspace;
        $stageId = $request->stageId();

        return $this->page($request, [
            'mode' => 'create',
            'idea_stage_id' => $stageId !== null && $workspace->ideaStages()->whereKey($stageId)->exists() ? $stageId : null,
        ]);
    }

    public function show(ListIdeasRequest $request, Idea $idea): Response
    {
        $this->authorize('view', $idea);

        return $this->page($request, [
            'mode' => 'edit',
            'idea' => IdeaResource::make($idea->load('labels:id'))->resolve(),
        ]);
    }

    public function store(StoreIdeaRequest $request): RedirectResponse
    {
        CreateIdea::execute($request->user()->currentWorkspace, $request->user(), $request->validated());

        return back();
    }

    public function update(UpdateIdeaRequest $request, Idea $idea): RedirectResponse
    {
        UpdateIdea::execute($idea, $request->validated());

        return back();
    }

    public function destroy(Idea $idea): RedirectResponse
    {
        $this->authorize('delete', $idea);

        DeleteIdeas::execute($idea->workspace, [$idea->id]);

        return back();
    }

    public function bulkDestroy(BulkDestroyIdeasRequest $request): RedirectResponse
    {
        DeleteIdeas::execute($request->user()->currentWorkspace, data_get($request->validated(), 'idea_ids'));

        return back();
    }

    public function duplicate(Request $request, Idea $idea): RedirectResponse
    {
        $this->authorize('update', $idea);

        DuplicateIdea::execute($idea, $request->user());

        return back();
    }

    public function move(MoveIdeaRequest $request, Idea $idea): RedirectResponse
    {
        MoveIdea::execute(
            $idea,
            data_get($request->validated(), 'idea_stage_id'),
            data_get($request->validated(), 'idea_ids'),
        );

        return back();
    }

    /**
     * @param  array<string, mixed>|null  $editor
     */
    private function page(ListIdeasRequest $request, ?array $editor): Response
    {
        $workspace = $request->user()->currentWorkspace;

        return Inertia::render('create/Ideas', BuildIdeasPageProps::execute($request, $workspace, $editor));
    }
}
