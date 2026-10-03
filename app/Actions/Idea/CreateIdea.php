<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Actions\Media\SyncOwnedMedia;
use App\Models\Idea;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;

class CreateIdea
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Workspace $workspace, User $user, array $data): Idea
    {
        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($workspace, $user, $data): Idea {
            $stageId = data_get($data, 'idea_stage_id');

            $idea = $workspace->ideas()->create([
                'user_id' => $user->id,
                'idea_stage_id' => $stageId,
                'title' => data_get($data, 'title'),
                'body' => data_get($data, 'body'),
                'media' => [],
                'position' => self::nextPosition($workspace, $stageId),
            ]);

            SyncOwnedMedia::execute(
                $idea,
                array_map(fn (string $id): array => ['id' => $id], data_get($data, 'media_ids', [])),
                $batch,
                errorKey: 'media_ids',
            );

            $idea->labels()->sync(data_get($data, 'label_ids', []));

            return $idea;
        });
    }

    public static function nextPosition(Workspace $workspace, ?string $stageId): int
    {
        $last = Idea::query()
            ->where('workspace_id', $workspace->id)
            ->where('idea_stage_id', $stageId)
            ->max('position');

        return $last === null ? 0 : (int) $last + 1;
    }
}
