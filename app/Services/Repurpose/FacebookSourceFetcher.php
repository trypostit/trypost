<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Facebook\StoryMediaType;
use App\Enums\Facebook\StoryStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;

class FacebookSourceFetcher extends MetaSourceFetcher
{
    private const VIDEO_FIELDS = 'id,source,description,permalink_url,created_time';

    private const PAGE_SIZE = 25;

    private const PUBLIC_VIDEO_FIELDS = 'id,source,description,created_time';

    /**
     * @param  array<int, SourceFormat>  $formats
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since, array $formats): array
    {
        $wantsReels = in_array(SourceFormat::Reel, $formats, true);
        $wantsVideos = in_array(SourceFormat::Video, $formats, true);

        $reels = $wantsReels || $wantsVideos
            ? $this->videos($account, 'video_reels', $since, SourceFormat::Reel)
            : [];

        $videos = $wantsVideos
            ? $this->videos($account, 'videos', $since, SourceFormat::Video)
            : [];

        $stories = in_array(SourceFormat::Story, $formats, true)
            ? $this->stories($account, $since)
            : [];

        $reelIds = array_map(fn (SourceMedia $media): string => $media->id, $reels);
        $videos = array_values(array_filter(
            $videos,
            fn (SourceMedia $media): bool => ! in_array($media->id, $reelIds, true),
        ));

        return [...($wantsReels ? $reels : []), ...$videos, ...$stories];
    }

    /**
     * @return array<int, SourceMedia>
     */
    private function videos(SocialAccount $account, string $edge, ?CarbonInterface $since, SourceFormat $format): array
    {
        $rows = $this->rowsWithFallback(
            $account,
            "{$this->graphApi()}/{$account->platform_user_id}/{$edge}",
            ['fields' => self::VIDEO_FIELDS, 'limit' => self::PAGE_SIZE, 'since' => $since?->getTimestamp()],
            self::PUBLIC_VIDEO_FIELDS,
        );

        return array_map(
            fn (array $row): SourceMedia => new SourceMedia(
                id: (string) data_get($row, 'id'),
                format: $format,
                downloadUrl: data_get($row, 'source'),
                caption: (string) data_get($row, 'description', ''),
                permalink: data_get($row, 'permalink_url'),
                createdAt: $this->timestamp($row, 'created_time'),
            ),
            $rows,
        );
    }

    /**
     * @return array<int, SourceMedia>
     */
    private function stories(SocialAccount $account, ?CarbonInterface $since): array
    {
        $rows = $this->rows($account, "{$this->graphApi()}/{$account->platform_user_id}/stories", [
            'fields' => 'post_id,status,creation_time,media_type,media_id,url',
            'limit' => self::PAGE_SIZE,
            'since' => $since?->getTimestamp(),
        ]);

        return collect($rows)
            ->filter(fn (array $row): bool => $this->isPublishedVideo($row))
            ->map(fn (array $row): SourceMedia => $this->toStory($account, $row))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isPublishedVideo(array $row): bool
    {
        return StoryMediaType::tryFrom((string) data_get($row, 'media_type')) === StoryMediaType::Video
            && StoryStatus::tryFrom((string) data_get($row, 'status')) === StoryStatus::Published;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toStory(SocialAccount $account, array $row): SourceMedia
    {
        $mediaId = (string) data_get($row, 'media_id');

        return new SourceMedia(
            id: (string) data_get($row, 'post_id', $mediaId),
            format: SourceFormat::Story,
            downloadUrl: $this->videoSource($account, $mediaId),
            caption: '',
            permalink: data_get($row, 'url'),
            createdAt: $this->timestamp($row, 'creation_time'),
        );
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
