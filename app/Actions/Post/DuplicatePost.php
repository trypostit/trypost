<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Support\Media\MediaCopyBatch;
use Illuminate\Validation\ValidationException;

/**
 * Duplicate one account's version into an independent draft.
 */
class DuplicatePost
{
    public static function execute(Post $original, User $user, ?string $targetId = null): Post
    {
        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($original, $user, $targetId): Post {
            $targets = $original->postPlatforms()
                ->enabled()
                ->whereHas('socialAccount')
                ->get();
            $target = $targetId === null && $targets->count() === 1
                ? $targets->first()
                : $targets->firstWhere('id', $targetId);

            if ($target === null) {
                throw ValidationException::withMessages([
                    'post_platform_id' => __('validation.exists', ['attribute' => 'post platform']),
                ]);
            }

            return CreateChannelPost::execute($original->workspace, $user, [
                ...self::destination($original, $target),
                'status' => PostStatus::Draft->value,
                'created_via' => CreatedVia::Web,
            ], $batch);
        });
    }

    /**
     * The original's content, media, labels and per-platform settings for one target,
     * ready for CreateChannelPost.
     *
     * @return array<string, mixed>
     */
    public static function destination(Post $original, PostPlatform $target): array
    {
        return [
            'content' => $original->content,
            'media' => array_map(fn (array $item): array => [
                'id' => data_get($item, 'id'),
                'meta' => data_get($item, 'meta'),
                ...array_intersect_key($item, array_flip(['source', 'source_meta'])),
            ], $original->media ?? []),
            'social_account_id' => $target->social_account_id,
            'content_type' => $target->content_type->value,
            'meta' => $target->meta ?? [],
            'label_ids' => $original->labels()->pluck('workspace_labels.id')->all(),
            'legacy_media' => $original->media ?? [],
        ];
    }
}
