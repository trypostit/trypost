<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Contracts\Analytics\PublicationHistoryCollector;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class XPublicationCollector extends AbstractApiPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 100;

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $response = $this->get(
            $account,
            config('trypost.platforms.x.api')."/users/{$account->platform_user_id}/tweets",
            [
                'max_results' => self::PAGE_SIZE,
                'pagination_token' => $cursor,
                'tweet.fields' => 'created_at,attachments',
                'expansions' => 'attachments.media_keys',
                'media.fields' => 'media_key,type,preview_image_url,url',
            ],
        );
        $media = collect((array) $response->json('includes.media', []))->keyBy('media_key');
        $publications = [];
        $crossedCutoff = false;
        $providerLimited = false;

        foreach ((array) $response->json('data', []) as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'created_at'));

            if (! $publishedAt) {
                $providerLimited = true;

                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $attachedMedia = collect((array) data_get($row, 'attachments.media_keys', []))
                ->map(fn (mixed $key) => $media->get((string) $key))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->values();
            $types = $attachedMedia->pluck('type')->filter()->unique()->values();
            $postId = (string) data_get($row, 'id');
            $preview = $attachedMedia->first(
                fn (array $item): bool => filled(data_get($item, 'preview_image_url')) || filled(data_get($item, 'url')),
            );

            $publications[] = new DiscoveredPublication(
                providerPostId: $postId,
                publishedAt: $publishedAt,
                contentType: $this->contentType($types->all(), $attachedMedia->count()),
                providerContentType: $types->implode(','),
                permalink: filled($account->username) ? "https://x.com/{$account->username}/status/{$postId}" : null,
                excerpt: data_get($row, 'text'),
                previewMetadata: is_array($preview)
                    ? ['thumbnail_url' => data_get($preview, 'preview_image_url') ?: data_get($preview, 'url')]
                    : null,
            );
        }

        $nextCursor = data_get($response->json(), 'meta.next_token');
        $hasNext = is_string($nextCursor) && $nextCursor !== '' && ! $crossedCutoff;

        return new PublicationPage($publications, $hasNext ? $nextCursor : null, ! $hasNext, $providerLimited);
    }

    /** @param list<mixed> $types */
    private function contentType(array $types, int $mediaCount): PublicationContentType
    {
        if (in_array('video', $types, true) || in_array('animated_gif', $types, true)) {
            return PublicationContentType::Video;
        }

        if ($mediaCount > 1) {
            return PublicationContentType::Carousel;
        }

        return in_array('photo', $types, true) ? PublicationContentType::Image : PublicationContentType::Text;
    }
}
