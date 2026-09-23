<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Contracts\Analytics\PublicationHistoryCollector;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class PinterestPublicationCollector extends AbstractApiPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 250;

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $response = $this->get($account, config('trypost.platforms.pinterest.api').'/pins', [
            'page_size' => self::PAGE_SIZE,
            'bookmark' => $cursor,
        ]);
        $publications = [];
        $providerLimited = false;

        foreach ((array) $response->json('items', []) as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'created_at'));

            if (! $publishedAt) {
                $providerLimited = true;

                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                continue;
            }

            $postId = (string) data_get($row, 'id');
            $providerType = strtolower((string) data_get($row, 'media.media_type'));
            $thumbnail = data_get($row, 'media.images.600x.url')
                ?: data_get($row, 'media.images.originals.url');

            $publications[] = new DiscoveredPublication(
                providerPostId: $postId,
                publishedAt: $publishedAt,
                contentType: $this->contentType($providerType),
                providerContentType: $providerType ?: null,
                permalink: "https://www.pinterest.com/pin/{$postId}/",
                excerpt: data_get($row, 'description') ?: data_get($row, 'title'),
                previewMetadata: filled($thumbnail) ? ['thumbnail_url' => $thumbnail] : null,
                providerMetadata: ['metric_time_basis' => MetricTimeBasis::Lifetime->value],
            );
        }

        $nextCursor = data_get($response->json(), 'bookmark');
        $hasNext = is_string($nextCursor) && $nextCursor !== '';

        return new PublicationPage(
            $publications,
            $hasNext ? $nextCursor : null,
            ! $hasNext,
            $providerLimited,
            canStopAtTarget: false,
        );
    }

    private function contentType(string $providerType): PublicationContentType
    {
        return match (true) {
            str_contains($providerType, 'video') => PublicationContentType::Video,
            str_contains($providerType, 'multiple'), str_contains($providerType, 'carousel') => PublicationContentType::Carousel,
            str_contains($providerType, 'image') => PublicationContentType::Image,
            default => PublicationContentType::Unknown,
        };
    }
}
