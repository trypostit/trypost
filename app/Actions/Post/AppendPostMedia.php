<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\User;
use App\Support\ChannelPermissions;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostApproval;
use App\Support\PostStatusRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AppendPostMedia
{
    /**
     * Appends media items to a post under its approval lock, deciding from the
     * status re-read there. A post that is publishing or settled is refused. On
     * networks whose type follows the media (Pinterest, TikTok) the type is derived
     * again from the new media. Changing an approved, scheduled post goes back
     * through UpdatePost, so a member who needs approval sends it back for
     * approval like any other edit, even when the approval landed just before.
     * A poll, which takes no media, is refused.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    public static function execute(Post $post, array $items, User $actor): void
    {
        PostApproval::locked($post, fn () => MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($post, $items, $actor): void {
            $post->appendMedia($items, $batch);
            self::ensureAcceptsMedia($post, $actor);

            if ($post->hasDestination() && ContentType::derivesFromMedia($post->platform)) {
                $post->update(['content_type' => ContentType::forMedia($post->platform, $post->media ?? [])]);
            }

            if ($post->status === PostStatus::Scheduled && PostApproval::isRequired($post->workspace, $actor, $post->status->value)) {
                UpdatePost::execute($post->workspace, $post, ['status' => $post->status->value], $actor);
            }
        }));
    }

    /**
     * These uploads belong to this request until the append adopts them. A refusal
     * after a download or upload releases them. Previously issued upload tokens
     * stay available to their owner through the normal execute() path.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function attachRequestUploads(Post $post, array $items, User $actor): void
    {
        try {
            self::execute($post, $items, $actor);
        } catch (Throwable $exception) {
            DB::transaction(fn () => DeleteOwnedMedia::forRows(Arr::pluck($items, 'id'), fn (Builder $query) => $query->temporaryUploads()));

            throw $exception;
        }
    }

    /** Check before fetching a file and again under the post's row lock when attaching it. */
    public static function ensureAcceptsMedia(Post $post, User $actor, string $field = 'media'): void
    {
        if ($post->content_type?->isPoll() === true) {
            throw ValidationException::withMessages([$field => trans('posts.errors.media_not_accepted')]);
        }

        if (PostStatusRules::blocksEditing($post)) {
            throw ValidationException::withMessages(['post' => PostStatusRules::editBlockedMessage()]);
        }

        if ($post->status === PostStatus::Scheduled && $post->socialAccount !== null) {
            ChannelPermissions::ensureScheduledPostCanChange($post->socialAccount, $post, $actor);
        }
    }
}
