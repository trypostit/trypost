<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\FindWorkspaceAsset;
use App\Models\Media;
use App\Models\Post;
use App\Support\PostStatusRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachExistingAsset
{
    public const UNSUPPORTED_TYPE_MESSAGE = 'This file type is not supported by the platforms enabled on the post.';

    public const ASSET_NOT_FOUND_MESSAGE = 'Asset not found.';

    /**
     * Append a snapshot of the workspace asset to the post exactly once.
     */
    public static function execute(Post $post, Media $media, ?string $alt = null): void
    {
        DB::transaction(function () use ($post, $media, $alt): void {
            $fresh = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (PostStatusRules::blocksEditing($fresh)) {
                throw ValidationException::withMessages([
                    'asset_id' => PostStatusRules::editBlockedMessage(),
                ]);
            }

            $workspace = $fresh->workspace;

            if ($workspace === null) {
                throw ValidationException::withMessages([
                    'asset_id' => self::ASSET_NOT_FOUND_MESSAGE,
                ]);
            }

            $asset = FindWorkspaceAsset::execute($workspace, $media->id, lockForUpdate: true);

            if ($asset === null) {
                throw ValidationException::withMessages([
                    'asset_id' => self::ASSET_NOT_FOUND_MESSAGE,
                ]);
            }

            if (! in_array($asset->type, $fresh->allowedMediaTypes(), true)) {
                throw ValidationException::withMessages([
                    'asset_id' => self::UNSUPPORTED_TYPE_MESSAGE,
                ]);
            }

            $alreadyAttached = collect($fresh->media ?? [])
                ->contains(fn (array $row): bool => data_get($row, 'id') === $asset->id);

            if ($alreadyAttached) {
                $post->setRawAttributes($fresh->getAttributes(), true);

                return;
            }

            $fresh->update([
                'media' => collect($fresh->media ?? [])->push(self::snapshot($asset, $alt))->all(),
            ]);
            $post->setRawAttributes($fresh->getAttributes(), true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Media $media, ?string $alt): array
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

        return $item;
    }
}
