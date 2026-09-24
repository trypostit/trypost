<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BlueskyPublicationCollector extends AbstractApiPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 100;

    private const HYDRATION_BATCH_SIZE = 25;

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $pds = rtrim((string) data_get(
            $account->meta,
            'service',
            config('trypost.platforms.bluesky.default_service'),
        ), '/');
        $response = $this->get(
            $account,
            "{$pds}/xrpc/".BlueskyLexicon::LIST_RECORDS,
            [
                'repo' => $account->platform_user_id,
                'collection' => BlueskyLexicon::FEED_POST,
                'limit' => self::PAGE_SIZE,
                'cursor' => $cursor,
                'reverse' => true,
            ],
            authenticated: false,
        );
        $records = collect((array) $response->json('records', []));
        $hydrated = $this->hydrate($account, $records->pluck('uri')->filter()->values());
        $publications = [];
        $crossedCutoff = false;

        foreach ($records as $record) {
            $publishedAt = $this->publishedAt(data_get($record, 'value.createdAt'));

            if (! $publishedAt) {
                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $uri = (string) data_get($record, 'uri');
            $postId = basename($uri);
            $view = $hydrated->get($uri);

            $publications[] = new DiscoveredPublication(
                providerPostId: $postId,
                publishedAt: $publishedAt,
                contentType: $this->contentType((array) data_get($record, 'value.embed', [])),
                providerContentType: data_get($record, 'value.embed.$type'),
                permalink: filled($account->username)
                    ? "https://bsky.app/profile/{$account->username}/post/{$postId}"
                    : "https://bsky.app/profile/{$account->platform_user_id}/post/{$postId}",
                excerpt: data_get($record, 'value.text'),
                previewMetadata: $this->preview(is_array($view) ? $view : []),
                providerMetadata: $this->providerMetadata(is_array($view) ? $view : []),
            );
        }

        $nextCursor = data_get($response->json(), 'cursor');
        $hasNext = is_string($nextCursor) && $nextCursor !== '' && ! $crossedCutoff;

        return new PublicationPage($publications, $hasNext ? $nextCursor : null, ! $hasNext);
    }

    /**
     * @param  Collection<int, mixed>  $uris
     * @return Collection<string, array<string, mixed>>
     */
    private function hydrate(SocialAccount $account, Collection $uris): Collection
    {
        $appView = rtrim((string) config('trypost.platforms.bluesky.public_appview'), '/');
        $posts = collect();

        foreach ($uris->chunk(self::HYDRATION_BATCH_SIZE) as $batch) {
            $response = $this->get(
                $account,
                "{$appView}/xrpc/".BlueskyLexicon::GET_POSTS,
                ['uris' => $batch->values()->all()],
                authenticated: false,
            );

            $posts->push(...(array) $response->json('posts', []));
        }

        return $posts->filter(fn (mixed $post): bool => is_array($post))->keyBy('uri');
    }

    /** @param array<string, mixed> $embed */
    private function contentType(array $embed): PublicationContentType
    {
        $type = (string) data_get($embed, '$type');
        $media = str_ends_with($type, 'recordWithMedia') ? (array) data_get($embed, 'media', []) : $embed;
        $mediaType = (string) data_get($media, '$type');

        return match (true) {
            str_ends_with($mediaType, 'video') => PublicationContentType::Video,
            str_ends_with($mediaType, 'gallery'), count((array) data_get($media, 'images', [])) > 1 => PublicationContentType::Carousel,
            str_ends_with($mediaType, 'images') => PublicationContentType::Image,
            str_ends_with($type, 'external') => PublicationContentType::Link,
            default => PublicationContentType::Text,
        };
    }

    /** @param array<string, mixed> $view */
    private function preview(array $view): ?array
    {
        $thumbnail = data_get($view, 'embed.images.0.thumb')
            ?: data_get($view, 'embed.thumbnail')
            ?: data_get($view, 'embed.media.images.0.thumb')
            ?: data_get($view, 'embed.media.thumbnail');

        return filled($thumbnail) ? ['thumbnail_url' => $thumbnail] : null;
    }

    /** @param array<string, mixed> $view */
    private function providerMetadata(array $view): array
    {
        return [
            'like_count' => (int) data_get($view, 'likeCount', 0),
            'repost_count' => (int) data_get($view, 'repostCount', 0),
            'reply_count' => (int) data_get($view, 'replyCount', 0),
            'quote_count' => (int) data_get($view, 'quoteCount', 0),
            'public_metrics_available' => $view !== [],
        ];
    }
}
