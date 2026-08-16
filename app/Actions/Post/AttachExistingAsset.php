<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Models\Media;
use App\Models\Post;
use App\Support\PostStatusRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachExistingAsset
{
    public const UNSUPPORTED_TYPE_MESSAGE = 'This file type is not supported by the platforms enabled on the post.';

    /**
     * Append a snapshot of the workspace asset to the post exactly once.
     */
    public static function execute(Post $post, Media $media, ?string $alt = null): void
    {
        $item = [
            'id' => $media->id,
            'path' => $media->path,
            'url' => $media->url,
            'type' => $media->type->value,
            'mime_type' => $media->mime_type,
            'original_filename' => $media->original_filename,
            'size' => $media->size,
        ];

        $meta = is_array($media->meta) ? $media->meta : [];

        if (filled($alt) && $media->isImage()) {
            $meta['alt_text'] = $alt;
        }

        if ($meta !== []) {
            $item['meta'] = $meta;
        }

        DB::transaction(function () use ($post, $media, $item): void {
            $fresh = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (PostStatusRules::blocksEditing($fresh)) {
                throw ValidationException::withMessages([
                    'asset_id' => PostStatusRules::editBlockedMessage(),
                ]);
            }

            if (! in_array($media->type, $fresh->allowedMediaTypes(), true)) {
                throw ValidationException::withMessages([
                    'asset_id' => self::UNSUPPORTED_TYPE_MESSAGE,
                ]);
            }

            $alreadyAttached = collect($fresh->media ?? [])
                ->contains(fn (array $row): bool => data_get($row, 'id') === $media->id);

            if ($alreadyAttached) {
                $post->setRawAttributes($fresh->getAttributes(), true);

                return;
            }

            $fresh->update([
                'media' => collect($fresh->media ?? [])->push($item)->all(),
            ]);
            $post->setRawAttributes($fresh->getAttributes(), true);
        });
    }
}
