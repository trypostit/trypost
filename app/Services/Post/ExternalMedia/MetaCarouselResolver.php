<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

/**
 * Instagram (both connections) and Threads list a carousel's media as the
 * children of the post on their Graph API.
 */
class MetaCarouselResolver extends AbstractMediaResolver
{
    private const string FIELDS = 'media_type,media_url,children{media_type,media_url}';

    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $media = $this->getJson($account, "{$account->platform->graphApiUrl()}/{$publication->remote_id}", ['fields' => self::FIELDS]);

        return $this->remoteFiles(collect(data_get($media, 'children.data') ?: [$media])->pluck('media_url'));
    }
}
