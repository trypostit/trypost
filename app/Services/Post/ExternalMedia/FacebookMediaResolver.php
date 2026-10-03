<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

class FacebookMediaResolver extends AbstractMediaResolver
{
    private const string FIELDS = 'attachments{media_type,type,media,target,subattachments{media_type,type,media,target}}';

    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $api = (string) config('trypost.platforms.facebook.graph_api');
        $attachment = (array) data_get($this->getJson($account, "{$api}/{$publication->remote_id}", ['fields' => self::FIELDS]), 'attachments.data.0', []);
        $items = data_get($attachment, 'subattachments.data') ?: [$attachment];

        return $this->remoteFiles(collect($items)->filter(fn (mixed $item): bool => $this->isOwnMedia($item))->map(fn (mixed $item): mixed => $this->isVideo($item)
            ? data_get($this->getJson($account, "{$api}/{$this->videoId($item)}", ['fields' => 'source']), 'source')
            : data_get($item, 'media.image.src')));
    }

    /**
     * Photos, albums and videos only: a shared link also carries an image,
     * but it is the linked page's preview, not media of this post.
     */
    private function isOwnMedia(mixed $item): bool
    {
        $type = strtolower("{$this->stringValue($item, 'media_type')} {$this->stringValue($item, 'type')}");

        return ! str_contains($type, 'share') && (bool) preg_match('/\b(?:photo|album)\b|video/', $type);
    }

    private function isVideo(mixed $item): bool
    {
        $type = strtolower("{$this->stringValue($item, 'media_type')} {$this->stringValue($item, 'type')}");

        return str_contains($type, 'video') && filled($this->videoId($item));
    }

    private function videoId(mixed $item): string
    {
        return $this->stringValue($item, 'target.id');
    }

    private function stringValue(mixed $item, string $key): string
    {
        return (string) data_get($item, $key, '');
    }
}
