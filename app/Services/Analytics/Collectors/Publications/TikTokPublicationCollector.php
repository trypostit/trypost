<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Support\Analytics\InvalidPublicationCursor;
use Carbon\CarbonImmutable;

class TikTokPublicationCollector extends AbstractApiPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 20;

    private const FIELDS = 'id,title,video_description,create_time,duration,cover_image_url,share_url';

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        if (! in_array('video.list', $account->scopes ?? [], true)) {
            return new PublicationPage([], null, true, true);
        }

        $payload = ['max_count' => self::PAGE_SIZE];

        if (is_string($cursor) && ctype_digit($cursor)) {
            $payload['cursor'] = (int) $cursor;
        }

        $response = $this->post(
            $account,
            config('trypost.platforms.tiktok.api').'/video/list/?fields='.self::FIELDS,
            $payload,
            hasCursor: $cursor !== null,
        );
        $errorCode = data_get($response->json(), 'error.code');

        if (is_string($errorCode) && ! in_array($errorCode, ['', 'ok'], true)) {
            if ($cursor !== null && $errorCode === 'invalid_params' && InvalidPublicationCursor::matches($response)) {
                throw new AnalyticsCollectionException('invalid_cursor', 'TikTok publication history cursor expired');
            }

            $category = match ($errorCode) {
                'rate_limit_exceeded' => 'rate_limited',
                'internal_error' => 'transient',
                default => 'permission',
            };

            throw new AnalyticsCollectionException(
                $category,
                "TikTok video list failed with {$errorCode}",
            );
        }

        $publications = [];
        $crossedCutoff = false;
        $providerLimited = false;

        foreach ((array) $response->json('data.videos', []) as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'create_time'), timestamp: true);

            if (! $publishedAt) {
                $providerLimited = true;

                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $postId = (string) data_get($row, 'id');
            $cover = data_get($row, 'cover_image_url');

            $publications[] = new DiscoveredPublication(
                providerPostId: $postId,
                publishedAt: $publishedAt,
                contentType: PublicationContentType::Video,
                providerContentType: 'video',
                permalink: data_get($row, 'share_url'),
                excerpt: data_get($row, 'video_description') ?: data_get($row, 'title'),
                previewMetadata: is_string($cover) && $cover !== '' ? [
                    'thumbnail_url' => $cover,
                    'expires_at' => $this->coverExpiresAt($cover),
                ] : null,
                providerMetadata: ['duration' => data_get($row, 'duration')],
            );
        }

        $nextCursor = data_get($response->json(), 'data.cursor');
        $hasNext = (bool) data_get($response->json(), 'data.has_more')
            && is_numeric($nextCursor)
            && ! $crossedCutoff;

        return new PublicationPage(
            $publications,
            $hasNext ? (string) $nextCursor : null,
            ! $hasNext,
            $providerLimited,
        );
    }

    private function coverExpiresAt(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = data_get($query, 'x-expires') ?? data_get($query, 'expires');

        return is_scalar($expires) && ctype_digit((string) $expires)
            ? CarbonImmutable::createFromTimestampUTC((int) $expires)->toIso8601String()
            : null;
    }
}
