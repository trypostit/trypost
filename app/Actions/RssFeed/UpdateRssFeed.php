<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Models\RssFeed;
use Illuminate\Support\Arr;

class UpdateRssFeed
{
    /**
     * @param  array{custom_title?: ?string, rss_feed_collection_id?: ?string}  $data
     */
    public static function execute(RssFeed $feed, array $data): RssFeed
    {
        $attributes = Arr::only($data, ['custom_title', 'rss_feed_collection_id']);

        if (array_key_exists('custom_title', $attributes)) {
            $title = trim((string) data_get($attributes, 'custom_title'));
            $attributes['custom_title'] = $title === '' ? null : $title;
        }

        $feed->update($attributes);

        return $feed;
    }
}
