<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Duplicate a post into an independent draft for the same channel.
 */
class DuplicatePost
{
    public static function execute(Post $original, User $user): Post
    {
        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($original, $user): Post {
            if ($original->socialAccount === null) {
                throw ValidationException::withMessages([
                    'post' => __('posts.errors.choose_channel'),
                ]);
            }

            return CreateChannelPost::execute($original->workspace, $user, [
                ...self::destination($original),
                'status' => PostStatus::Draft->value,
                'created_via' => CreatedVia::Web,
            ], $batch);
        });
    }

    /**
     * The original's content, media, labels, channel and settings, ready for
     * CreateChannelPost.
     *
     * @return array<string, mixed>
     */
    public static function destination(Post $original): array
    {
        return [
            'content' => $original->content,
            'media' => array_map(fn (array $item): array => [
                'id' => data_get($item, 'id'),
                'meta' => data_get($item, 'meta'),
                ...array_intersect_key($item, array_flip(['source', 'source_meta'])),
            ], $original->media ?? []),
            'social_account_id' => $original->social_account_id,
            'content_type' => $original->content_type->value,
            'meta' => Arr::except($original->meta ?? [], PostPlatformMetaRules::SYSTEM_KEYS),
            'label_ids' => $original->labels()->pluck('workspace_labels.id')->all(),
            'legacy_media' => $original->media ?? [],
        ];
    }
}
