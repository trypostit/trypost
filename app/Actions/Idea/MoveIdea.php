<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Models\Idea;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveIdea
{
    /**
     * @param  list<string>  $orderedIdeaIds
     */
    public static function execute(Idea $idea, ?string $stageId, array $orderedIdeaIds): void
    {
        DB::transaction(function () use ($idea, $stageId, $orderedIdeaIds): void {
            $locked = Idea::query()
                ->where('workspace_id', $idea->workspace_id)
                ->where(function ($query) use ($idea, $stageId): void {
                    $query->whereKey($idea->id)->orWhere('idea_stage_id', $stageId);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('idea_stage_id', 'id');

            if (! $locked->has($idea->id)) {
                throw (new ModelNotFoundException)->setModel(Idea::class, [$idea->id]);
            }

            $expected = $locked->keys()->all();
            $requested = array_values($orderedIdeaIds);

            if (count($requested) !== count($expected) || array_diff($expected, $requested) !== [] || array_diff($requested, $expected) !== []) {
                throw ValidationException::withMessages([
                    'idea_ids' => __('create.ideas.errors.stale_idea_order'),
                ]);
            }

            $idea->update(['idea_stage_id' => $stageId]);

            foreach ($requested as $position => $id) {
                Idea::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
