<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Repurpose\SourceFetchException;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class InstagramSourceFetcher implements SourceFetcher
{
    private const FIELDS = 'id,media_type,media_product_type,media_url,caption,permalink,timestamp';

    /**
     * @param  array<int, SourceFormat>  $formats
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since, array $formats): array
    {
        $media = [];

        if (in_array(SourceFormat::Reel, $formats, true) || in_array(SourceFormat::Video, $formats, true)) {
            $media = $this->request($account, 'media', $since);
        }

        if (in_array(SourceFormat::Story, $formats, true)) {
            $media = [...$media, ...$this->request($account, 'stories', null)];
        }

        return array_map($this->toSourceMedia(...), $media);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function request(SocialAccount $account, string $edge, ?CarbonInterface $since): array
    {
        $response = Http::withToken($account->access_token)
            ->get("{$this->graphApi($account)}/{$account->platform_user_id}/{$edge}", array_filter([
                'fields' => self::FIELDS,
                'limit' => 50,
                'since' => $since?->getTimestamp(),
            ]));

        $this->assertSucceeded($response);

        return (array) $response->json('data', []);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toSourceMedia(array $row): SourceMedia
    {
        return new SourceMedia(
            id: (string) data_get($row, 'id'),
            format: $this->format($row),
            downloadUrl: data_get($row, 'media_url'),
            caption: (string) data_get($row, 'caption', ''),
            permalink: data_get($row, 'permalink'),
            createdAt: ($timestamp = data_get($row, 'timestamp')) ? Carbon::parse($timestamp) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function format(array $row): ?SourceFormat
    {
        if (data_get($row, 'media_type') !== 'VIDEO') {
            return null;
        }

        return match (data_get($row, 'media_product_type')) {
            'REELS' => SourceFormat::Reel,
            'FEED' => SourceFormat::Video,
            'STORY' => SourceFormat::Story,
            default => null,
        };
    }

    private function assertSucceeded(Response $response): void
    {
        if ($response->failed()) {
            throw new SourceFetchException($response);
        }
    }

    private function graphApi(SocialAccount $account): string
    {
        return $account->platform === Platform::InstagramFacebook
            ? config('trypost.platforms.instagram-facebook.graph_api')
            : config('trypost.platforms.instagram.graph_api');
    }
}
