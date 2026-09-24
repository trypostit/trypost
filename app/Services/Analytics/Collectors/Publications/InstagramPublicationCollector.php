<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class InstagramPublicationCollector extends AbstractMetaPublicationCollector
{
    private const PAGE_SIZE = 50;

    public function page(
        SocialAccount $account,
        ?string $cursor,
        CarbonImmutable $cutoff,
    ): PublicationPage {
        $fields = $account->platform === Platform::InstagramFacebook
            ? 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp'
            : 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp';
        $response = $this->get(
            $account,
            "{$account->platform->instagramGraphBaseUrl()}/{$account->platform_user_id}/media",
            ['fields' => $fields, 'limit' => self::PAGE_SIZE, 'after' => $cursor],
        );
        $publications = [];
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

            $publications[] = new DiscoveredPublication(
                providerPostId: (string) data_get($row, 'id'),
                publishedAt: $publishedAt,
                contentType: $this->contentType($row, $account->platform),
                providerContentType: data_get($row, 'media_product_type') ?: data_get($row, 'media_type'),
                permalink: data_get($row, 'permalink'),
                excerpt: data_get($row, 'caption'),
                previewMetadata: $this->preview(data_get($row, 'thumbnail_url') ?: data_get($row, 'media_url')),
                providerMetadata: null,
            );
        }

        return $this->result($publications, $response, $crossedCutoff, $providerLimited);
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
