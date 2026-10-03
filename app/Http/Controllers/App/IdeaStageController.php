<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Idea\CreateIdeaStage;
use App\Actions\Idea\DeleteIdeaStage;
use App\Actions\Idea\ReorderIdeaStages;
use App\Actions\Idea\UpdateIdeaStage;
use App\Http\Requests\App\Idea\ReorderIdeaStagesRequest;
use App\Http\Requests\App\Idea\StoreIdeaStageRequest;
use App\Http\Requests\App\Idea\UpdateIdeaStageRequest;
use App\Models\IdeaStage;
use Illuminate\Http\RedirectResponse;

class IdeaStageController extends Controller
{
    public function store(StoreIdeaStageRequest $request): RedirectResponse
    {
        CreateIdeaStage::execute($request->user()->currentWorkspace, $request->validated());

        return back();
    }

    public function reorder(ReorderIdeaStagesRequest $request): RedirectResponse
    {
        ReorderIdeaStages::execute($request->user()->currentWorkspace, data_get($request->validated(), 'stage_ids'));

        return back();
    }

    public function update(UpdateIdeaStageRequest $request, IdeaStage $ideaStage): RedirectResponse
    {
        UpdateIdeaStage::execute($ideaStage, $request->validated());

        return back();
    }

    public function destroy(IdeaStage $ideaStage): RedirectResponse
    {
        $this->authorize('delete', $ideaStage);

        DeleteIdeaStage::execute($ideaStage);

        return back();
    }
}
