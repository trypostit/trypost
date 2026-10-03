<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Lists /media page by page and, on the first page of every run, the stories
 * live on /stories: Instagram only returns a story for 24 hours, so the
 * discovery cadence is what captures them.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/stories
 */
class InstagramPublicationCollector extends AbstractMetaPublicationCollector
{
    private const PAGE_SIZE = 50;

    private const STORIES_LIMIT = 100;

    public function page(
        SocialAccount $account,
        ?string $cursor,
        CarbonImmutable $cutoff,
    ): PublicationPage {
        $response = $this->get(
            $account,
            "{$account->platform->instagramGraphBaseUrl()}/{$account->platform_user_id}/media",
            ['fields' => $this->fields($account), 'limit' => self::PAGE_SIZE, 'after' => $cursor],
        );
        $publications = $cursor === null ? $this->liveStories($account) : [];
        $crossedCutoff = false;
        $providerLimited = false;

        foreach ((array) $response->json('data', []) as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'timestamp'));

            if (! $publishedAt) {
                $providerLimited = true;

                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $publications[] = $this->publication($row, $publishedAt, $this->contentType($row, $account->platform));
        }

        return $this->result($publications, $response, $crossedCutoff, $providerLimited);
    }

    /**
     * Stories skip the cutoff: the edge only returns the last 24 hours, and a
     * story older than the discovery overlap is still one nobody has seen.
     * A refused stories read never costs the feed page; throttling still does.
     *
     * @return list<DiscoveredPublication>
     */
    private function liveStories(SocialAccount $account): array
    {
        try {
            $response = $this->get(
                $account,
                "{$account->platform->instagramGraphBaseUrl()}/{$account->platform_user_id}/stories",
                ['fields' => $this->fields($account), 'limit' => self::STORIES_LIMIT],
            );
        } catch (AnalyticsCollectionException $exception) {
            if (! in_array($exception->category, ['permission', 'malformed'], true)) {
                throw $exception;
            }

            return [];
        }

        return collect((array) $response->json('data', []))
            ->map(fn (array $row): ?DiscoveredPublication => ($publishedAt = $this->publishedAt(data_get($row, 'timestamp')))
                ? $this->publication($row, $publishedAt, PublicationContentType::Story, 'STORY')
                : null)
            ->filter()
            ->values()
            ->all();
    }

    private function fields(SocialAccount $account): string
    {
        return $account->platform === Platform::InstagramFacebook
            ? 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp'
            : 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp';
    }

    /** @param array<string, mixed> $row */
    private function publication(
        array $row,
        CarbonImmutable $publishedAt,
        PublicationContentType $contentType,
        ?string $providerContentType = null,
    ): DiscoveredPublication {
        return new DiscoveredPublication(
            providerPostId: (string) data_get($row, 'id'),
            publishedAt: $publishedAt,
            contentType: $contentType,
            providerContentType: $providerContentType ?? (data_get($row, 'media_product_type') ?: data_get($row, 'media_type')),
            permalink: data_get($row, 'permalink'),
            excerpt: data_get($row, 'caption'),
            previewMetadata: $this->preview(data_get($row, 'thumbnail_url') ?: data_get($row, 'media_url')),
            providerMetadata: null,
        );
    }

    /** @param array<string, mixed> $row */
    private function contentType(array $row, Platform $platform): PublicationContentType
    {
        $mediaType = strtoupper((string) data_get($row, 'media_type'));
        $productType = strtoupper((string) data_get($row, 'media_product_type'));

        return match (true) {
            $productType === 'STORY' => PublicationContentType::Story,
            $productType === 'REELS' => PublicationContentType::Reel,
            $mediaType === 'CAROUSEL_ALBUM' => PublicationContentType::Carousel,
            $mediaType === 'VIDEO' && $platform === Platform::Instagram => PublicationContentType::Reel,
            $mediaType === 'VIDEO' => PublicationContentType::Video,
            $mediaType === 'IMAGE' => PublicationContentType::Image,
            default => PublicationContentType::Unknown,
        };
    }
}
