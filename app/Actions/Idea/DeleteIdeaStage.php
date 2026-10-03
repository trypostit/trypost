<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Models\Idea;
use App\Models\IdeaStage;
use Illuminate\Support\Facades\DB;

class DeleteIdeaStage
{
    public static function execute(IdeaStage $stage): void
    {
        DB::transaction(function () use ($stage): void {
            $lastPosition = Idea::query()
                ->where('workspace_id', $stage->workspace_id)
                ->whereNull('idea_stage_id')
                ->max('position');

            $next = $lastPosition === null ? 0 : $lastPosition + 1;

            $stage->ideas()
                ->reorder()
                ->orderBy('position')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->each(function (Idea $idea) use (&$next): void {
                    $idea->update(['idea_stage_id' => null, 'position' => $next++]);
                });

            $workspaceId = $stage->workspace_id;

            $stage->delete();

            IdeaStage::query()
                ->where('workspace_id', $workspaceId)
                ->orderBy('position')
                ->get()
                ->each(function (IdeaStage $remaining, int $position): void {
                    if ($remaining->position !== $position) {
                        $remaining->update(['position' => $position]);
                    }
                });
        });
    }
}
