<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class YouTubePublicationCollector extends AbstractApiPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 50;

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $api = config('trypost.platforms.youtube.data_api');
        $channel = $this->get($account, "{$api}/channels", [
            'part' => 'contentDetails',
            'id' => $account->platform_user_id,
            'maxResults' => 1,
        ]);
        $uploadsPlaylist = data_get($channel->json(), 'items.0.contentDetails.relatedPlaylists.uploads');

        if (! is_string($uploadsPlaylist) || $uploadsPlaylist === '') {
            throw AnalyticsCollectionException::malformed('YouTube channel did not expose an uploads playlist');
        }

        $playlist = $this->get($account, "{$api}/playlistItems", [
            'part' => 'contentDetails',
            'playlistId' => $uploadsPlaylist,
            'maxResults' => self::PAGE_SIZE,
            'pageToken' => $cursor,
        ]);
        $videoIds = collect((array) $playlist->json('items', []))
            ->pluck('contentDetails.videoId')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values();
        $videos = collect();

        if ($videoIds->isNotEmpty()) {
            $videosResponse = $this->get($account, "{$api}/videos", [
                'part' => 'snippet,contentDetails',
                'id' => $videoIds->implode(','),
                'maxResults' => self::PAGE_SIZE,
            ]);
            $videos = collect((array) $videosResponse->json('items', []))->keyBy('id');
        }

        $publications = [];
        $crossedCutoff = false;
        $providerLimited = false;

        foreach ($videoIds as $videoId) {
            $row = $videos->get($videoId);

            if (! is_array($row)) {
                $providerLimited = true;

                continue;
            }

            $publishedAt = $this->publishedAt(data_get($row, 'snippet.publishedAt'));

            if (! $publishedAt) {
                $providerLimited = true;

                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $thumbnail = data_get($row, 'snippet.thumbnails.maxres.url')
                ?: data_get($row, 'snippet.thumbnails.high.url')
                ?: data_get($row, 'snippet.thumbnails.medium.url')
                ?: data_get($row, 'snippet.thumbnails.default.url');

            $publications[] = new DiscoveredPublication(
                providerPostId: (string) $videoId,
                publishedAt: $publishedAt,
                contentType: PublicationContentType::Video,
                providerContentType: 'video',
                permalink: "https://www.youtube.com/watch?v={$videoId}",
                excerpt: data_get($row, 'snippet.description') ?: data_get($row, 'snippet.title'),
                previewMetadata: filled($thumbnail) ? ['thumbnail_url' => $thumbnail] : null,
                providerMetadata: [
                    'duration' => data_get($row, 'contentDetails.duration'),
                    'short_classification' => 'unknown',
                ],
            );
        }

        $nextCursor = data_get($playlist->json(), 'nextPageToken');
        $hasNext = is_string($nextCursor) && $nextCursor !== '' && ! $crossedCutoff;

        return new PublicationPage($publications, $hasNext ? $nextCursor : null, ! $hasNext, $providerLimited);
    }
}
