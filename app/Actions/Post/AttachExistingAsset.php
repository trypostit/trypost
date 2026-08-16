<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

class AttachExistingAsset
{
    /**
     * Append a snapshot of the workspace asset to the post exactly once.
     *
     * Returns true when a new item was appended and false when the same
     * asset was already attached. The duplicate check runs inside the
     * row lock so concurrent API/MCP calls stay idempotent.
     */
    public static function execute(Post $post, Media $media, ?string $alt = null): bool
    {
        return DB::transaction(function () use ($post, $media, $alt): bool {
            $fresh = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $items = collect($fresh->media ?? []);

            if ($items->contains(fn (array $item): bool => data_get($item, 'id') === $media->id)) {
                $post->setRawAttributes($fresh->getAttributes(), true);

                return false;
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

            $fresh->update([
                'media' => $items->push($item)->all(),
            ]);

            $post->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }
}
