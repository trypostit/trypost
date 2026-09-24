<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\PostComment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('posts:split-legacy-active')]
#[Description('Split editable multi-target posts into one post per enabled target')]
class SplitLegacyActivePosts extends Command
{
    public function handle(): int
    {
        $splitPosts = 0;
        $createdPosts = 0;

        Post::query()
            ->whereIn('status', [Status::Draft, Status::Scheduled])
            ->whereHas('postPlatforms', fn ($query) => $query->enabled(), '>', 1)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($posts) use (&$splitPosts, &$createdPosts): void {
                foreach ($posts as $candidate) {
                    $clones = DB::transaction(fn (): int => $this->splitPost($candidate->id));
                    if ($clones > 0) {
                        $splitPosts++;
                        $createdPosts += $clones;
                    }
                }
            });

        $this->info("Split {$splitPosts} original posts and created {$createdPosts} independent posts.");

        return self::SUCCESS;
    }

    private function splitPost(string $postId): int
    {
        $post = Post::query()->lockForUpdate()->findOrFail($postId);
        if (! in_array($post->status, [Status::Draft, Status::Scheduled], true)) {
            return 0;
        }

        $targets = $post->postPlatforms()->enabled()->orderBy('id')->lockForUpdate()->get();
        if ($targets->count() <= 1) {
            return 0;
        }

        $labelIds = $post->labels()->pluck('workspace_labels.id')->all();
        $comments = $post->comments()->orderBy('id')->get();

        foreach ($targets->skip(1) as $target) {
            $clone = Post::withoutEvents(function () use ($post): Post {
                $clone = $post->replicate();
                $clone->created_at = $post->created_at;
                $clone->updated_at = $post->updated_at;
                $clone->saveQuietly();

                return $clone;
            });
            $clone->labels()->sync($labelIds);
            $commentIds = [];

            foreach ($comments as $comment) {
                $copy = PostComment::withoutEvents(function () use ($comment, $clone): PostComment {
                    $copy = $comment->replicate();
                    $copy->post_id = $clone->id;
                    $copy->parent_id = null;
                    $copy->created_at = $comment->created_at;
                    $copy->updated_at = $comment->updated_at;
                    $copy->saveQuietly();

                    return $copy;
                });
                $commentIds[$comment->id] = $copy->id;
            }

            foreach ($comments as $comment) {
                if ($comment->parent_id !== null) {
                    DB::table('post_comments')->where('id', $commentIds[$comment->id])
                        ->update(['parent_id' => $commentIds[$comment->parent_id]]);
                }
            }

            $target->updateQuietly(['post_id' => $clone->id]);
        }

        return $targets->count() - 1;
    }
}
