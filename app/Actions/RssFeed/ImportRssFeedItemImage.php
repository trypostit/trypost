<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Actions\Media\DeleteOwnedMedia;
use App\Models\Media;
use App\Models\RssFeedItem;
use App\Services\Post\MediaAttacher;
use Illuminate\Support\Facades\DB;

class ImportRssFeedItemImage
{
    /**
     * The item owns its imported image: it is downloaded once as a temporary
     * upload and re-pointed to the item. Saving the item as an idea or a post
     * copies it, so the item keeps its own.
     */
    public static function execute(RssFeedItem $item): ?Media
    {
        $existing = $item->image()->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($item->image_url === null) {
            return null;
        }

        $upload = app(MediaAttacher::class)->hostImage($item->feed->workspace, $item->image_url);

        if ($upload === null) {
            return null;
        }

        return DB::transaction(function () use ($item, $upload): ?Media {
            $locked = RssFeedItem::query()->whereKey($item->id)->lockForUpdate()->first();
            $existing = $locked?->image()->first();

            if ($locked === null || $existing !== null) {
                DeleteOwnedMedia::forRows([$upload->id]);

                return $existing;
            }

            $upload->update([
                'rss_feed_item_id' => $locked->id,
                'mediable_type' => null,
                'mediable_id' => null,
                'collection' => Media::COLLECTION_MEDIA,
                'upload_token' => null,
            ]);

            return $upload->fresh();
        });
    }
}
