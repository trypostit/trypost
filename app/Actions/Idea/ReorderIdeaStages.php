<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Models\IdeaStage;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderIdeaStages
{
    /**
     * @param  list<string>  $stageIds
     */
    public static function execute(Workspace $workspace, array $stageIds): void
    {
        DB::transaction(function () use ($workspace, $stageIds): void {
            $currentIds = IdeaStage::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $requested = array_values($stageIds);

            if (count($requested) !== count($currentIds) || array_diff($currentIds, $requested) !== [] || array_diff($requested, $currentIds) !== []) {
                throw ValidationException::withMessages([
                    'stage_ids' => __('create.ideas.errors.stale_order'),
                ]);
            }

            foreach ($requested as $position => $id) {
                IdeaStage::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
