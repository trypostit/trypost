<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Support\Analytics\YouTubeScopes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class YouTubePublicationCollector extends AbstractPublicationHistoryCollector
{
    private const PAGE_SIZE = 50;

    private const UPLOADS_PLAYLIST_CACHE_DAYS = 30;

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        if ($account->missingScope(YouTubeScopes::READ) !== null) {
            return PublicationPage::scopeMissing();
        }

        $api = config('trypost.platforms.youtube.data_api');
        $uploadsPlaylist = $this->uploadsPlaylist($account, (string) $api);
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
                contentTypeReported: false,
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

    private function uploadsPlaylist(SocialAccount $account, string $api): string
    {
        return Cache::remember(
            "analytics:youtube-uploads:{$account->id}:{$account->platform_user_id}",
            now()->addDays(self::UPLOADS_PLAYLIST_CACHE_DAYS),
            function () use ($account, $api): string {
                $channel = $this->get($account, "{$api}/channels", [
                    'part' => 'contentDetails',
                    'id' => $account->platform_user_id,
                    'maxResults' => 1,
                ]);
                $uploadsPlaylist = data_get($channel->json(), 'items.0.contentDetails.relatedPlaylists.uploads');

                if (! is_string($uploadsPlaylist) || $uploadsPlaylist === '') {
                    throw AnalyticsCollectionException::malformed('YouTube channel did not expose an uploads playlist');
                }

                return $uploadsPlaylist;
            },
        );
    }
}
