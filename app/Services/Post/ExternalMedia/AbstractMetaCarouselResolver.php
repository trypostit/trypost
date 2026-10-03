<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

abstract class AbstractMetaCarouselResolver extends AbstractMediaResolver
{
    private const string FIELDS = 'media_type,media_url,children{media_type,media_url}';

    abstract protected function baseUrl(SocialAccount $account): string;

    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $media = $this->getJson($account, "{$this->baseUrl($account)}/{$publication->remote_id}", ['fields' => self::FIELDS]);

        return $this->remoteFiles(collect(data_get($media, 'children.data') ?: [$media])->pluck('media_url'));
    }
}
