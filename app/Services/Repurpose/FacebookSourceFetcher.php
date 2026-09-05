<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class FacebookSourceFetcher extends MetaSourceFetcher
{
    private const VIDEO_FIELDS = 'id,source,description,permalink_url,created_time';

    /**
     * @param  array<int, SourceFormat>  $formats
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since, array $formats): array
    {
        $reels = in_array(SourceFormat::Reel, $formats, true)
            ? $this->videos($account, 'video_reels', $since, SourceFormat::Reel)
            : [];

        $videos = in_array(SourceFormat::Video, $formats, true)
            ? $this->videos($account, 'videos', $since, SourceFormat::Video)
            : [];

        $stories = in_array(SourceFormat::Story, $formats, true)
            ? $this->stories($account)
            : [];

        if ($reels !== [] && $videos !== []) {
            $reelIds = array_map(fn (SourceMedia $media): string => $media->id, $reels);
            $videos = array_values(array_filter(
                $videos,
                fn (SourceMedia $media): bool => ! in_array($media->id, $reelIds, true),
            ));
        }

        return [...$reels, ...$videos, ...$stories];
    }

    /**
     * @return array<int, SourceMedia>
     */
    private function videos(SocialAccount $account, string $edge, ?CarbonInterface $since, SourceFormat $format): array
    {
        $rows = $this->rows($account, "{$this->graphApi()}/{$account->platform_user_id}/{$edge}", [
            'fields' => self::VIDEO_FIELDS,
            'limit' => 50,
            'since' => $since?->getTimestamp(),
        ]);

        return array_map(
            fn (array $row): SourceMedia => new SourceMedia(
                id: (string) data_get($row, 'id'),
                format: $format,
                downloadUrl: data_get($row, 'source'),
                caption: (string) data_get($row, 'description', ''),
                permalink: data_get($row, 'permalink_url'),
                createdAt: ($createdTime = data_get($row, 'created_time')) ? Carbon::parse($createdTime) : null,
            ),
            $rows,
        );
    }

    /**
     * @return array<int, SourceMedia>
     */
    private function stories(SocialAccount $account): array
    {
        $rows = $this->rows($account, "{$this->graphApi()}/{$account->platform_user_id}/stories", [
            'fields' => 'post_id,status,creation_time,media_type,media_id,url',
            'limit' => 50,
        ]);

        $stories = [];

        foreach ($rows as $row) {
            if (data_get($row, 'media_type') !== 'video' || data_get($row, 'status') !== 'PUBLISHED') {
                continue;
            }

            $mediaId = (string) data_get($row, 'media_id');

            $stories[] = new SourceMedia(
                id: (string) data_get($row, 'post_id', $mediaId),
                format: SourceFormat::Story,
                downloadUrl: $this->videoSource($account, $mediaId),
                caption: '',
                permalink: data_get($row, 'url'),
                createdAt: ($createdTime = data_get($row, 'creation_time')) ? Carbon::parse($createdTime) : null,
            );
        }

        return $stories;
    }

    private function videoSource(SocialAccount $account, string $videoId): ?string
    {
        if ($videoId === '') {
            return null;
        }

        $response = $this->http($account)->get("{$this->graphApi()}/{$videoId}", ['fields' => 'source']);

        return $response->successful() ? $response->json('source') : null;
    }

    private function graphApi(): string
    {
        return config('trypost.platforms.facebook.graph_api');
    }
}
