<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

class MastodonPublicationCollector extends AbstractApiPublicationCollector implements PublicationHistoryCollector
{
    private const PAGE_SIZE = 40;

    private const PARTIAL_REASON = 'mastodon_reconnect_for_private_history';

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        $hasPrivateHistoryScope = count(array_intersect(['read', 'read:statuses'], $account->scopes ?? [])) > 0;
        $instance = rtrim((string) data_get(
            $account->meta,
            'instance',
            config('trypost.platforms.mastodon.default_instance'),
        ), '/');
        $response = $this->get(
            $account,
            "{$instance}/api/v1/accounts/{$account->platform_user_id}/statuses",
            [
                'limit' => self::PAGE_SIZE,
                'exclude_reblogs' => true,
                'max_id' => $cursor,
            ],
            authenticated: $hasPrivateHistoryScope,
        );
        $rows = (array) $response->json();
        $publications = [];
        $crossedCutoff = false;

        foreach ($rows as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'created_at'));

            if (! $publishedAt) {
                continue;
            }

            if ($publishedAt->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $attachments = (array) data_get($row, 'media_attachments', []);
            $thumbnail = data_get($attachments, '0.preview_url') ?: data_get($attachments, '0.url');

            $publications[] = new DiscoveredPublication(
                providerPostId: (string) data_get($row, 'id'),
                publishedAt: $publishedAt,
                contentType: $this->contentType($row, $attachments),
                providerContentType: data_get($attachments, '0.type') ?: (data_get($row, 'poll') ? 'poll' : 'status'),
                permalink: data_get($row, 'url'),
                excerpt: $this->plainText((string) data_get($row, 'content', '')),
                previewMetadata: filled($thumbnail) ? ['thumbnail_url' => $thumbnail] : null,
                providerMetadata: [
                    'visibility' => data_get($row, 'visibility'),
                    'favourites_count' => (int) data_get($row, 'favourites_count', 0),
                    'reblogs_count' => (int) data_get($row, 'reblogs_count', 0),
                    'replies_count' => (int) data_get($row, 'replies_count', 0),
                    'history_visibility' => $hasPrivateHistoryScope ? 'authorized' : 'public_only',
                    'reconnect_required' => ! $hasPrivateHistoryScope,
                ],
            );
        }

        $nextCursor = $crossedCutoff ? null : $this->nextCursor($response, $rows);

        return new PublicationPage(
            publications: $publications,
            nextCursor: $nextCursor,
            providerExhausted: $crossedCutoff || $nextCursor === null,
            partialReason: $hasPrivateHistoryScope ? null : self::PARTIAL_REASON,
        );
    }

    /** @param array<string, mixed> $row @param array<int, mixed> $attachments */
    private function contentType(array $row, array $attachments): PublicationContentType
    {
        $types = collect($attachments)->pluck('type');

        return match (true) {
            $types->contains(fn (mixed $type): bool => in_array($type, ['video', 'gifv'], true)) => PublicationContentType::Video,
            count($attachments) > 1 => PublicationContentType::Carousel,
            $types->contains('image') => PublicationContentType::Image,
            data_get($row, 'poll') !== null => PublicationContentType::Poll,
            filled(data_get($row, 'card.url')) => PublicationContentType::Link,
            default => PublicationContentType::Text,
        };
    }

    /** @param array<int, mixed> $rows */
    private function nextCursor(Response $response, array $rows): ?string
    {
        $link = $response->header('Link');

        if (is_string($link) && preg_match('/<([^>]+)>;\s*rel="next"/', $link, $matches) === 1) {
            parse_str((string) parse_url(data_get($matches, 1), PHP_URL_QUERY), $query);
            $cursor = data_get($query, 'max_id');

            if (is_scalar($cursor) && (string) $cursor !== '') {
                return (string) $cursor;
            }
        }

        if (count($rows) < self::PAGE_SIZE) {
            return null;
        }

        $lastId = data_get($rows, (count($rows) - 1).'.id');

        return is_scalar($lastId) && (string) $lastId !== '' ? (string) $lastId : null;
    }

    private function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
