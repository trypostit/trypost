<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostApproval;

class AppendPostMedia
{
    /**
     * Appends media items to a post under its approval lock, deciding from the
     * status re-read there. Changing an approved, scheduled post goes back
     * through UpdatePost, so a member who needs approval sends it back for
     * approval like any other edit, even when the approval landed just before.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function execute(Post $post, array $items, ?User $actor): void
    {
        PostApproval::locked($post, fn () => MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($post, $items, $actor): void {
            $post->appendMedia($items, $batch);

            if ($post->status === PostStatus::Scheduled && PostApproval::isRequired($post->workspace, $actor, $post->status->value)) {
                UpdatePost::execute($post->workspace, $post, ['status' => $post->status->value], $actor);
            }
        }));
    }
}
