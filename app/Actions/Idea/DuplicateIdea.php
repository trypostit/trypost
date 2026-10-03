<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Actions\Media\SyncOwnedMedia;
use App\Models\Idea;
use App\Models\User;
use App\Support\Media\MediaCopyBatch;

class DuplicateIdea
{
    /**
     * The copy owns its own files: every item the original owns is copied,
     * and a stored item without a row passes through as it is.
     */
    public static function execute(Idea $idea, User $user): Idea
    {
        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($idea, $user): Idea {
            Idea::query()
                ->where('workspace_id', $idea->workspace_id)
                ->where('idea_stage_id', $idea->idea_stage_id)
                ->where('position', '>', $idea->position)
                ->increment('position');

            $items = array_values($idea->media ?? []);

            $copy = $idea->replicate();
            $copy->media = [];
            $copy->position = $idea->position + 1;
            $copy->user_id = $user->id;
            $copy->save();

            SyncOwnedMedia::execute($copy, $items, $batch, errorKey: 'media_ids', legacyItems: $items);

            $copy->labels()->sync($idea->labels()->pluck('workspace_labels.id')->all());

            return $copy;
        });
    }
}
