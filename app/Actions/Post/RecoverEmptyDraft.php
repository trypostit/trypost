<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RecoverEmptyDraft
{
    /**
     * Replaces an empty legacy draft with independent posts. The legacy draft
     * is deleted only after the new posts exist, in the same transaction: the
     * first post that uses one of its rows takes the row over (no file copy),
     * later posts copy it, and whatever is left is released with the draft.
     *
     * @param  array<string, mixed>  $composition
     * @return Collection<int, Post>
     */
    public static function execute(Workspace $workspace, User $user, Post $legacy, array $composition): Collection
    {
        $locked = null;

        return CreatePosts::execute(
            $workspace,
            $user,
            $composition,
            beforeCreate: function (MediaCopyBatch $batch) use ($workspace, $legacy, &$locked): void {
                $locked = $workspace->posts()->lockForUpdate()->findOrFail($legacy->id);

                if ($locked->status !== Status::Draft || $locked->postPlatforms()->enabled()->exists()) {
                    throw ValidationException::withMessages([
                        'recover_post_id' => __('validation.in', ['attribute' => 'recovery post']),
                    ]);
                }

                $batch->releaseOwner('post_id', $locked->id);
            },
            afterCreate: function () use (&$locked): void {
                DeletePost::execute($locked);
            },
            legacyMedia: $legacy->media ?? [],
        );
    }
}
