<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Actions\Media\SyncOwnedMedia;
use App\Models\Idea;
use App\Support\Media\MediaCopyBatch;

class UpdateIdea
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Idea $idea, array $data): Idea
    {
        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($idea, $data): Idea {
            $attributes = [];

            foreach (['title', 'body'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = data_get($data, $field);
                }
            }

            if (array_key_exists('idea_stage_id', $data) && data_get($data, 'idea_stage_id') !== $idea->idea_stage_id) {
                $attributes['idea_stage_id'] = data_get($data, 'idea_stage_id');
                $attributes['position'] = CreateIdea::nextPosition($idea->workspace, data_get($data, 'idea_stage_id'));
            }

            $idea->update($attributes);

            if (array_key_exists('media_ids', $data)) {
                $stored = collect($idea->media ?? [])->keyBy('id');

                SyncOwnedMedia::execute(
                    $idea,
                    array_map(fn (string $id): array => $stored->get($id) ?? ['id' => $id], data_get($data, 'media_ids', [])),
                    $batch,
                    errorKey: 'media_ids',
                );
            }

            if (array_key_exists('label_ids', $data)) {
                $idea->labels()->sync(data_get($data, 'label_ids', []));
            }

            return $idea;
        });
    }
}
