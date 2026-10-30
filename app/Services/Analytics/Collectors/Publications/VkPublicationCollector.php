<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use App\Services\Social\Vk\VkApi;
use App\Support\Analytics\VkAnalyticsResponse;
use Carbon\CarbonImmutable;

class VkPublicationCollector extends AbstractPublicationHistoryCollector
{
    private const PAGE_SIZE = 100;

    private const PARTIAL_REASON = 'vk_reconnect_with_user_token_for_history';

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        if (filled(data_get($account->meta, 'community_token'))) {
            // VK refuses every wall read with a community token (error 27),
            // so the wall's history can only be imported once the channel is
            // reconnected with a user token. Don't spend the request.
            return new PublicationPage(
                publications: [],
                nextCursor: null,
                providerExhausted: true,
                partialReason: self::PARTIAL_REASON,
            );
        }

        $ownerId = VkAnalyticsResponse::ownerId($account);
        $offset = max(0, (int) $cursor);

        $data = VkAnalyticsResponse::data($this->get($account, VkApi::endpoint('wall.get'), [
            'owner_id' => $ownerId,
            'filter' => 'owner',
            'count' => self::PAGE_SIZE,
            'offset' => $offset > 0 ? $offset : null,
            'v' => VkApi::version(),
        ]), 'publication history collection');

        $rows = (array) data_get($data, 'items', []);
        $publications = [];
        $crossedCutoff = false;

        foreach ($rows as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'date'), timestamp: true);

            if (! $publishedAt) {
                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                // A pinned post floats above the date order; skip it without
                // mistaking it for the end of the window.
                if ((int) data_get($row, 'is_pinned', 0) === 1) {
                    continue;
                }

                $crossedCutoff = true;

                break;
            }

            if (filled(data_get($row, 'copy_history'))) {
                // A repost of someone else's record, not the wall's own content.
                continue;
            }

            $attachments = (array) data_get($row, 'attachments', []);
            $thumbnail = $this->thumbnail($attachments);
            $postId = (string) data_get($row, 'id');

            $publications[] = new DiscoveredPublication(
                providerPostId: $postId,
                publishedAt: $publishedAt,
                contentType: $this->contentType($attachments),
                providerContentType: data_get($attachments, '0.type') ?: (string) data_get($row, 'post_type', 'post'),
                permalink: "https://vk.com/wall{$ownerId}_{$postId}",
                excerpt: trim((string) data_get($row, 'text', '')),
                previewMetadata: filled($thumbnail) ? ['thumbnail_url' => $thumbnail] : null,
                providerMetadata: [
                    'views_count' => (int) data_get($row, 'views.count', 0),
                    'likes_count' => (int) data_get($row, 'likes.count', 0),
                    'reposts_count' => (int) data_get($row, 'reposts.count', 0),
                    'comments_count' => (int) data_get($row, 'comments.count', 0),
                    'is_pinned' => (int) data_get($row, 'is_pinned', 0) === 1,
                ],
            );
        }

        $nextOffset = $offset + count($rows);
        $nextCursor = ! $crossedCutoff
            && count($rows) === self::PAGE_SIZE
            && $nextOffset < (int) data_get($data, 'count', 0)
            ? (string) $nextOffset
            : null;

        return new PublicationPage(
            publications: $publications,
            nextCursor: $nextCursor,
            providerExhausted: $crossedCutoff || $nextCursor === null,
        );
    }

    /** @param array<int, mixed> $attachments */
    private function contentType(array $attachments): PublicationContentType
    {
        $types = collect($attachments)->pluck('type');

        return match (true) {
            $types->contains('video') => PublicationContentType::Video,
            count($attachments) > 1 => PublicationContentType::Carousel,
            $types->contains('photo') => PublicationContentType::Image,
            $types->contains('poll') => PublicationContentType::Poll,
            $types->contains('link') => PublicationContentType::Link,
            default => PublicationContentType::Text,
        };
    }

    /** @param array<int, mixed> $attachments */
    private function thumbnail(array $attachments): ?string
    {
        foreach ($attachments as $attachment) {
            $sizes = match (data_get($attachment, 'type')) {
                'photo' => (array) data_get($attachment, 'photo.sizes', []),
                'video' => (array) data_get($attachment, 'video.image', []),
                default => [],
            };

            if ($sizes === []) {
                continue;
            }

            // Sizes come smallest first; the `x` photo size (~600px) reads
            // well in a card, otherwise take the largest available.
            $preferred = collect($sizes)->firstWhere('type', 'x') ?? end($sizes);
            $url = data_get($preferred, 'url');

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
