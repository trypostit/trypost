<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Throwable;

class FacebookPublicationCollector extends AbstractMetaPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 50;

    private const FIELDS = 'id,message,created_time,permalink_url,status_type,attachments{media_type,type,url,media,target,subattachments}';

    public function page(
        SocialAccount $account,
        ?string $cursor,
        CarbonImmutable $cutoff,
    ): PublicationPage {
        $response = $this->get(
            $account,
            config('trypost.platforms.facebook.graph_api')."/{$account->platform_user_id}/published_posts",
            ['fields' => self::FIELDS, 'limit' => self::PAGE_SIZE, 'after' => $cursor],
        );
        $publications = [];
        $crossedCutoff = false;
        $providerLimited = false;

        foreach ((array) $response->json('data', []) as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'created_time'));

            if (! $publishedAt) {
                $providerLimited = true;

                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $attachment = (array) data_get($row, 'attachments.data.0', []);
            $previewUrl = data_get($attachment, 'media.image.src');
            $permalink = data_get($row, 'permalink_url');

            $videoId = $this->isVideo($attachment) ? data_get($attachment, 'target.id') : null;

            if (filled($videoId)) {
                $hydrated = $this->hydratePreview($account, (string) $videoId);
                $previewUrl = data_get($hydrated, 'picture') ?: $previewUrl;
                $permalink = $permalink ?: data_get($hydrated, 'permalink_url');
            }

            $publications[] = new DiscoveredPublication(
                providerPostId: (string) data_get($row, 'id'),
                publishedAt: $publishedAt,
                contentType: $this->contentType($attachment),
                providerContentType: data_get($attachment, 'type') ?: data_get($attachment, 'media_type') ?: data_get($row, 'status_type'),
                permalink: $permalink,
                excerpt: data_get($row, 'message'),
                previewMetadata: $this->preview($previewUrl),
                providerMetadata: filled($videoId) ? ['video_id' => (string) $videoId] : null,
            );
        }

        return $this->result($publications, $response, $crossedCutoff, $providerLimited);
    }

    /** @param array<string, mixed> $attachment */
    private function contentType(array $attachment): PublicationContentType
    {
        $mediaType = strtolower((string) data_get($attachment, 'media_type'));
        $type = strtolower((string) data_get($attachment, 'type'));
        $url = strtolower((string) data_get($attachment, 'url'));

        return match (true) {
            count((array) data_get($attachment, 'subattachments.data', [])) > 1 => PublicationContentType::Carousel,
            str_contains($type, 'reel'), str_contains($url, '/reel') => PublicationContentType::Reel,
            str_contains($mediaType, 'video'), str_contains($type, 'video') => PublicationContentType::Video,
            str_contains($mediaType, 'photo'), str_contains($mediaType, 'image') => PublicationContentType::Image,
            str_contains($type, 'share'), filled($url) => PublicationContentType::Link,
            $attachment === [] => PublicationContentType::Text,
            default => PublicationContentType::Unknown,
        };
    }

    /** @param array<string, mixed> $attachment */
    private function isVideo(array $attachment): bool
    {
        return in_array($this->contentType($attachment), [PublicationContentType::Video, PublicationContentType::Reel], true);
    }

    /** @return array<string, mixed> */
    private function hydratePreview(SocialAccount $account, string $videoId): array
    {
        try {
            return (array) $this->get(
                $account,
                config('trypost.platforms.facebook.graph_api')."/{$videoId}",
                ['fields' => 'picture,permalink_url'],
            )->json();
        } catch (Throwable) {
            return [];
        }
    }
}
