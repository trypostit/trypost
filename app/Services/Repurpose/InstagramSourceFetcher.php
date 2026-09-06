<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class InstagramSourceFetcher extends MetaSourceFetcher
{
    private const FIELDS = 'id,media_type,media_product_type,media_url,caption,permalink,timestamp';

    /** Everything Meta marks as public, so any Instagram token can read it. */
    private const PUBLIC_FIELDS = 'id,media_type,media_url,permalink,timestamp';

    /**
     * @param  array<int, SourceFormat>  $formats
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since, array $formats): array
    {
        $media = [];

        if (in_array(SourceFormat::Reel, $formats, true) || in_array(SourceFormat::Video, $formats, true)) {
            $media = array_map(
                fn (array $row): SourceMedia => $this->toSourceMedia($row, null),
                $this->request($account, 'media', $since),
            );
        }

        if (in_array(SourceFormat::Story, $formats, true)) {
            $media = [...$media, ...array_map(
                fn (array $row): SourceMedia => $this->toSourceMedia($row, SourceFormat::Story),
                $this->request($account, 'stories', null),
            )];
        }

        return $media;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function request(SocialAccount $account, string $edge, ?CarbonInterface $since): array
    {
        return $this->rowsWithFallback(
            $account,
            "{$this->graphApi($account)}/{$account->platform_user_id}/{$edge}",
            ['fields' => self::FIELDS, 'limit' => 50, 'since' => $since?->getTimestamp()],
            self::PUBLIC_FIELDS,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toSourceMedia(array $row, ?SourceFormat $edgeFormat): SourceMedia
    {
        return new SourceMedia(
            id: (string) data_get($row, 'id'),
            format: $this->format($row, $edgeFormat),
            downloadUrl: data_get($row, 'media_url'),
            caption: (string) data_get($row, 'caption', ''),
            permalink: data_get($row, 'permalink'),
            createdAt: ($timestamp = data_get($row, 'timestamp')) ? Carbon::parse($timestamp) : null,
        );
    }

    /**
     * Meta documents media_product_type as available to the Facebook-login API
     * only, and our standalone Instagram accounts talk to graph.instagram.com.
     * The surface is therefore taken from the edge that returned the row where
     * it is unambiguous, and a video off /media with no product type is read as
     * a Reel, which is what Instagram serves new feed video as.
     *
     * @param  array<string, mixed>  $row
     */
    private function format(array $row, ?SourceFormat $edgeFormat): ?SourceFormat
    {
        if (data_get($row, 'media_type') !== 'VIDEO') {
            return null;
        }

        if ($edgeFormat !== null) {
            return $edgeFormat;
        }

        return match (data_get($row, 'media_product_type')) {
            'REELS' => SourceFormat::Reel,
            'FEED' => SourceFormat::Video,
            'STORY' => SourceFormat::Story,
            default => SourceFormat::Reel,
        };
    }

    private function graphApi(SocialAccount $account): string
    {
        return $account->platform === Platform::InstagramFacebook
            ? config('trypost.platforms.instagram-facebook.graph_api')
            : config('trypost.platforms.instagram.graph_api');
    }
}
