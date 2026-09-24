<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecoverEmptyDraft
{
    /**
     * @param  array<string, mixed>  $composition
     * @return Collection<int, Post>
     */
    public static function execute(Workspace $workspace, User $user, Post $legacy, array $composition): Collection
    {
        return DB::transaction(function () use ($workspace, $user, $legacy, $composition): Collection {
            $locked = $workspace->posts()->lockForUpdate()->findOrFail($legacy->id);

            if ($locked->status !== Status::Draft || $locked->postPlatforms()->enabled()->exists()) {
                throw ValidationException::withMessages([
                    'recover_post_id' => __('validation.in', ['attribute' => 'recovery post']),
                ]);
            }

            $posts = CreatePosts::execute($workspace, $user, $composition);
            DeletePost::execute($locked);

            return $posts;
        });
    }
}
