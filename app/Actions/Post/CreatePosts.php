<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostCompositionValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreatePosts
{
    /**
     * @param  array<string, mixed>  $composition
     * @return Collection<int, Post>
     */
    public static function execute(Workspace $workspace, User $user, array $composition): Collection
    {
        $resolved = PostCompositionValidator::validate($workspace, $composition);

        return DB::transaction(function () use ($workspace, $user, $resolved): Collection {
            return collect($resolved['destinations'])->map(function (array $destination) use ($workspace, $user, $resolved): Post {
                $post = CreateChannelPost::execute($workspace, $user, [
                    ...$destination,
                    'status' => $resolved['status'],
                    'scheduled_at' => $resolved['scheduled_at'] ?? null,
                    'label_ids' => $resolved['label_ids'] ?? [],
                    'created_via' => $resolved['created_via'] ?? null,
                ]);

                if ($post->status === PostStatus::Publishing) {
                    PublishPost::dispatch($post)->afterCommit();
                }

                return $post;
            });
        });
    }
}
