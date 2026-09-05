<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A Facebook Page splits its video across three edges: `/video_reels` for
 * Reels, `/videos` for everything else, and `/stories` for Stories. Only the
 * edges the caller actually watches are requested.
 */
class FacebookSourceFetcher implements SourceFetcher
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

        // `/videos` also lists Reels, so anything already seen as a Reel is
        // dropped from the plain-video list rather than replicated twice.
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
        $response = Http::withToken($account->access_token)
            ->get("{$this->graphApi()}/{$account->platform_user_id}/{$edge}", array_filter([
                'fields' => self::VIDEO_FIELDS,
                'limit' => 50,
                'since' => $since?->getTimestamp(),
            ]));

        $this->assertSucceeded($response);

        return array_map(
            fn (array $row): SourceMedia => new SourceMedia(
                id: (string) data_get($row, 'id'),
                format: $format,
                downloadUrl: data_get($row, 'source'),
                caption: (string) data_get($row, 'description', ''),
                permalink: data_get($row, 'permalink_url'),
                createdAt: ($createdTime = data_get($row, 'created_time')) ? Carbon::parse($createdTime) : null,
            ),
            (array) $response->json('data', []),
        );
    }

    /**
     * The stories edge returns the story's media id and its Facebook URL but no
     * downloadable file, so the video behind each published story is resolved
     * in a second request.
     *
     * @return array<int, SourceMedia>
     */
    private function stories(SocialAccount $account): array
    {
        $response = Http::withToken($account->access_token)
            ->get("{$this->graphApi()}/{$account->platform_user_id}/stories", [
                'fields' => 'post_id,status,creation_time,media_type,media_id,url',
                'limit' => 50,
            ]);

        $this->assertSucceeded($response);

        $stories = [];

        foreach ((array) $response->json('data', []) as $row) {
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

        $response = Http::withToken($account->access_token)
            ->get("{$this->graphApi()}/{$videoId}", ['fields' => 'source']);

        return $response->successful() ? $response->json('source') : null;
    }

    private function assertSucceeded(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException((string) data_get($response->json(), 'error.message', $response->body()));
        }
    }

    private function graphApi(): string
    {
        return config('trypost.platforms.facebook.graph_api');
    }
}
