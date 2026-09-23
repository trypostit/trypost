<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Contracts\Analytics\PublicationHistoryCollector;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class ThreadsPublicationCollector extends AbstractMetaPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 50;

    private const FIELDS = 'id,media_product_type,media_type,media_url,permalink,text,timestamp,thumbnail_url,is_quote_post';

    public function page(
        SocialAccount $account,
        ?string $cursor,
        CarbonImmutable $cutoff,
    ): PublicationPage {
        $response = $this->get(
            $account,
            config('trypost.platforms.threads.graph_api')."/{$account->platform_user_id}/threads",
            ['fields' => self::FIELDS, 'limit' => self::PAGE_SIZE, 'after' => $cursor],
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
                contentType: $this->contentType($row),
                providerContentType: data_get($row, 'media_product_type') ?: data_get($row, 'media_type'),
                permalink: data_get($row, 'permalink'),
                excerpt: data_get($row, 'text'),
                previewMetadata: $this->preview(data_get($row, 'thumbnail_url') ?: data_get($row, 'media_url')),
                providerMetadata: ['is_quote_post' => (bool) data_get($row, 'is_quote_post', false)],
            );
        }

        return $this->result($publications, $response, $crossedCutoff, $providerLimited);
    }

    /** @param array<string, mixed> $row */
    private function contentType(array $row): PublicationContentType
    {
        return match (strtoupper((string) data_get($row, 'media_type'))) {
            'VIDEO' => PublicationContentType::Video,
            'IMAGE' => PublicationContentType::Image,
            'CAROUSEL_ALBUM' => PublicationContentType::Carousel,
            default => PublicationContentType::Text,
        };
    }
}
