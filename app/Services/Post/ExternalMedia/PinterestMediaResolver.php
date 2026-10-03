<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

class PinterestMediaResolver extends AbstractMediaResolver
{
    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $media = (array) data_get($this->getJson($account, config('trypost.platforms.pinterest.api')."/pins/{$publication->remote_id}"), 'media', []);
        $items = str_starts_with((string) data_get($media, 'media_type'), 'multiple')
            ? (array) data_get($media, 'items', [])
            : [$media];

        return $this->remoteFiles(collect($items)->map(fn (mixed $item): mixed => data_get($item, 'item_type', data_get($item, 'media_type')) === 'video'
            ? data_get($item, 'video_url')
            : data_get($item, 'images.1200x.url')));
    }
}
