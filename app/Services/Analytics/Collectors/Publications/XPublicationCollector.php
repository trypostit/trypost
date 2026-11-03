<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Models\SocialAccount;
use App\Support\Social\XPoll;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class XPublicationCollector extends AbstractPublicationHistoryCollector
{
    private const PAGE_SIZE = 100;

    public function page(SocialAccount $account, ?string $cursor, CarbonImmutable $cutoff): PublicationPage
    {
        if ($account->missingScope('tweet.read', 'users.read') !== null) {
            return PublicationPage::scopeMissing();
        }

        $response = $this->get(
            $account,
            config('trypost.platforms.x.api')."/users/{$account->platform_user_id}/tweets",
            [
                'max_results' => self::PAGE_SIZE,
                'start_time' => $cutoff->toIso8601ZuluString(),
                'pagination_token' => $cursor,
                'exclude' => 'retweets,replies',
                'tweet.fields' => 'created_at,attachments,note_tweet,referenced_tweets',
                'expansions' => 'attachments.media_keys,attachments.poll_ids',
                'media.fields' => 'media_key,type,preview_image_url,url,variants',
                'poll.fields' => XPoll::LOOKUP_FIELDS,
            ],
        );
        $polls = $response->collect('includes.polls')->keyBy('id');
        $media = collect((array) $response->json('includes.media', []))->keyBy('media_key');
        $publications = [];
        $crossedCutoff = false;
        $walkedRows = 0;
        $providerLimited = false;

        foreach ((array) $response->json('data', []) as $row) {
            $publishedAt = $this->publishedAt(data_get($row, 'created_at'));

            if ($publishedAt?->lessThan($cutoff)) {
                $crossedCutoff = true;

                break;
            }

            $walkedRows++;

            if (! $publishedAt) {
                $providerLimited = true;

                continue;
            }

            if (collect((array) data_get($row, 'referenced_tweets', []))->contains('type', 'replied_to')) {
                continue;
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

            $poll = transform($polls->get((string) data_get($row, 'attachments.poll_ids.0')), XPoll::result(...));

            $publications[] = new DiscoveredPublication(
                providerPostId: $postId,
                publishedAt: $publishedAt,
                contentType: $this->contentType($types->all(), $attachedMedia->count(), filled($poll)),
                providerContentType: $types->implode(','),
                permalink: filled($account->username) ? "https://x.com/{$account->username}/status/{$postId}" : null,
                excerpt: data_get($row, 'note_tweet.text') ?: data_get($row, 'text'),
                previewMetadata: is_array($preview)
                    ? ['thumbnail_url' => data_get($preview, 'preview_image_url') ?: data_get($preview, 'url')]
                    : null,
                providerMetadata: $this->providerMetadata($attachedMedia, $poll),
            );
        }

        $nextCursor = data_get($response->json(), 'meta.next_token');
        $hasNext = is_string($nextCursor) && $nextCursor !== '' && ! $crossedCutoff;

        return new PublicationPage(
            $publications,
            $hasNext ? $nextCursor : null,
            ! $hasNext,
            $providerLimited,
            providerRowCount: $walkedRows,
        );
    }

    /**
     * What discovery keeps of a post: its media and its poll's result.
     *
     * @param  Collection<int, array<string, mixed>>  $media
     * @param  array<string, mixed>|null  $poll
     * @return array<string, mixed>|null
     */
    private function providerMetadata(Collection $media, ?array $poll): ?array
    {
        $metadata = collect([
            'media' => $media->map(fn (array $item): array => Arr::whereNotNull(Arr::only($item, ['type', 'url', 'variants'])))->all(),
            'poll' => $poll,
        ])->filter(fn (mixed $value): bool => filled($value));

        return $metadata->isNotEmpty() ? $metadata->all() : null;
    }

    /** @param list<mixed> $types */
    private function contentType(array $types, int $mediaCount, bool $hasPoll): PublicationContentType
    {
        if ($hasPoll) {
            return PublicationContentType::Poll;
        }

        if (in_array('video', $types, true) || in_array('animated_gif', $types, true)) {
            return PublicationContentType::Video;
        }

        if ($mediaCount > 1) {
            return PublicationContentType::Carousel;
        }

        return in_array('photo', $types, true) ? PublicationContentType::Image : PublicationContentType::Text;
    }
}
