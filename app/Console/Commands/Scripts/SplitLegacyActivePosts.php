<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Media\SyncOwnedMedia;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostPlatform;
use App\Support\Media\MediaCopyBatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('posts:split-legacy-active')]
#[Description('Split editable and settled multi-target posts into one post per enabled target and group every multi-target post')]
class SplitLegacyActivePosts extends Command
{
    private const int UNFINISHED = -1;

    public function handle(): int
    {
        $splitPosts = 0;
        $createdPosts = 0;
        $unfinishedPosts = 0;

        Post::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $multiTarget) => $multiTarget
                    ->whereIn('status', [Status::Draft, Status::Scheduled, Status::Published, Status::PartiallyPublished, Status::Failed])
                    ->whereHas('postPlatforms', fn ($targets) => $targets->enabled(), '>', 1))
                ->orWhere('status', Status::PartiallyPublished))
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($posts) use (&$splitPosts, &$createdPosts, &$unfinishedPosts): void {
                foreach ($posts as $candidate) {
                    try {
                        $clones = MediaCopyBatch::run(fn (MediaCopyBatch $batch): int => $this->splitPost($candidate->id, $batch));
                    } catch (ValidationException) {
                        $this->warn("Post {$candidate->id} was not split: a media file could not be copied.");

                        continue;
                    }

                    if ($clones === self::UNFINISHED) {
                        $unfinishedPosts++;

                        continue;
                    }

                    if ($clones > 0) {
                        $splitPosts++;
                        $createdPosts += $clones;
                    }
                }
            });

        $this->info("Split {$splitPosts} original posts and created {$createdPosts} independent posts.");

        if ($unfinishedPosts > 0) {
            $this->warn("Left {$unfinishedPosts} settled post(s) with an unfinished target as they are.");
        }

        $groupedPosts = 0;

        Post::query()
            ->whereNull('post_group_id')
            ->has('postPlatforms', '>', 1)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($posts) use (&$groupedPosts): void {
                foreach ($posts as $post) {
                    $groupedPosts += Post::query()
                        ->whereKey($post->id)
                        ->whereNull('post_group_id')
                        ->toBase()
                        ->update(['post_group_id' => (string) Str::uuid7()]);
                }
            });

        $this->info("Grouped {$groupedPosts} multi-target posts.");

        return self::SUCCESS;
    }

    /**
     * Each copy keeps every item of the original, in order and as it is
     * (items without an id or repeated ones included); only an item pointing
     * at a row the original owns (an adopted library or an edit) is swapped
     * for the copy's own copy of that row. Library rows and missing files are
     * left for the adoption, which copies them per post.
     *
     * A settled post (published, partially published, failed) is split too:
     * each post takes the status of its own target, and the target row moves
     * with its permalink, error and analytics link. Disabled targets stay on
     * the original as the history of a destination that never ran. A settled
     * post with a target still in flight is left as it is (UNFINISHED).
     */
    private function splitPost(string $postId, MediaCopyBatch $batch): int
    {
        $post = Post::query()->lockForUpdate()->findOrFail($postId);
        $settled = $post->status->isSettled();

        if (! $settled && ! in_array($post->status, [Status::Draft, Status::Scheduled], true)) {
            return 0;
        }

        $targets = $post->postPlatforms()->enabled()->orderBy('id')->lockForUpdate()->get();
        $statuses = $settled ? $targets->map(fn (PostPlatform $target): ?Status => self::postStatusFor($target->status))->values() : collect();

        if ($statuses->contains(null)) {
            return self::UNFINISHED;
        }

        if ($settled && $targets->count() === 1) {
            $this->settleOriginal($post, $statuses->first(), $targets->first());
        }

        if ($targets->count() <= 1) {
            return 0;
        }

        if ($post->post_group_id === null) {
            $groupId = (string) Str::uuid7();
            Post::query()->whereKey($post->id)->toBase()->update(['post_group_id' => $groupId]);
            $post->forceFill(['post_group_id' => $groupId])->syncOriginal();
        }

        $items = array_values($post->media ?? []);
        $ownedIds = Media::query()
            ->where('post_id', $post->id)
            ->whereIn('id', collect($items)->map(fn (mixed $item): mixed => data_get($item, 'id'))->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))->unique()->values()->all())
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $labelIds = $post->labels()->pluck('workspace_labels.id')->all();
        $notes = $post->notes()->orderBy('id')->get();

        foreach ($targets->skip(1) as $index => $target) {
            $clone = Post::withoutEvents(function () use ($post, $settled, $statuses, $index, $target): Post {
                $clone = $post->replicate();
                $clone->status = $settled ? $statuses[$index] : $post->status;
                $clone->published_at = $settled ? $target->published_at : $post->published_at;
                $clone->created_at = $post->created_at;
                $clone->updated_at = $post->updated_at;
                $clone->saveQuietly();

                return $clone;
            });
            $this->copyOwnedMedia($clone, $items, $ownedIds, $batch);
            $clone->labels()->sync($labelIds);

            foreach ($notes as $note) {
                PostNote::withoutEvents(function () use ($note, $clone): void {
                    $copy = $note->replicate();
                    $copy->post_id = $clone->id;
                    $copy->created_at = $note->created_at;
                    $copy->updated_at = $note->updated_at;
                    $copy->saveQuietly();
                });
            }

            PostPlatform::query()->whereKey($target->id)->toBase()->update(['post_id' => $clone->id]);
            Post::query()->whereKey($clone->id)->toBase()->update(['updated_at' => $post->getRawOriginal('updated_at')]);
        }

        if ($settled) {
            $this->settleOriginal($post, $statuses->first(), $targets->first());
        }

        return $targets->count() - 1;
    }

    /**
     * @param  list<mixed>  $items
     * @param  list<string>  $ownedIds
     */
    private function copyOwnedMedia(Post $clone, array $items, array $ownedIds, MediaCopyBatch $batch): void
    {
        if ($ownedIds === []) {
            return;
        }

        $copies = array_combine($ownedIds, SyncOwnedMedia::execute($clone, array_map(fn (string $id): array => ['id' => $id], $ownedIds), $batch));

        $clone->forceFill(['media' => array_map(function (mixed $item) use ($copies): mixed {
            $copy = is_array($item) ? ($copies[data_get($item, 'id')] ?? null) : null;

            return $copy === null ? $item : [...$item, 'id' => $copy['id'], 'path' => $copy['path'], 'url' => $copy['url']];
        }, $items)])->saveQuietly();
    }

    private static function postStatusFor(PostPlatformStatus $status): ?Status
    {
        return match ($status) {
            PostPlatformStatus::Published => Status::Published,
            PostPlatformStatus::Failed, PostPlatformStatus::Rejected => Status::Failed,
            default => null,
        };
    }

    /**
     * The original keeps its first target: its status and publish time come
     * from that target. Query-builder writes, so no event fires and
     * `updated_at` stays.
     */
    private function settleOriginal(Post $post, Status $status, PostPlatform $target): void
    {
        Post::query()->whereKey($post->id)->toBase()->update([
            'status' => $status->value,
            'published_at' => $target->getRawOriginal('published_at'),
        ]);
    }
}
