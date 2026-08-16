<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Models\Media;
use App\Models\Post;

class AttachExistingAsset
{
    /**
     * Append a snapshot of the workspace asset to the post exactly once.
     */
    public static function execute(Post $post, Media $media, ?string $alt = null): void
    {
        $alreadyAttached = collect($post->media ?? [])
            ->contains(fn (array $item): bool => data_get($item, 'id') === $media->id);

        if ($alreadyAttached) {
            return;
        }

        $item = [
            'id' => $media->id,
            'path' => $media->path,
            'url' => $media->url,
            'type' => $media->type,
            'mime_type' => $media->mime_type,
            'original_filename' => $media->original_filename,
        ];

        if ($alt !== null && $media->isImage()) {
            $item['meta'] = ['alt_text' => $alt];
        }

        $post->appendMedia([$item]);
    }
}
