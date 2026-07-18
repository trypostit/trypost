<?php

declare(strict_types=1);

namespace App\Services\Social\LinkCard;

use App\Services\Brand\SafeHttpFetcher;
use App\Support\UrlDetector;
use Illuminate\Support\Facades\Cache;

/**
 * Builds a link preview card (OpenGraph metadata) for the first URL in a piece
 * of text. Shared by BlueskyPublisher (publish-time embed) and the editor
 * preview endpoint. Returns null whenever a card cannot or should not be built,
 * so callers degrade gracefully.
 */
class LinkCardFetcher
{
    /** Cards for a public URL are identical for everyone; cache briefly and globally. */
    private const int CACHE_MINUTES = 10;

    public function __construct(
        private readonly SafeHttpFetcher $http = new SafeHttpFetcher,
        private readonly OpenGraphExtractor $extractor = new OpenGraphExtractor,
    ) {}

    public function fetch(string $text): ?LinkCardMetadata
    {
        $url = UrlDetector::firstUrl($text);

        if ($url === null) {
            return null;
        }

        // Cache a plain array, never the DTO: a rich object does not round-trip
        // cleanly through every cache driver (it comes back as an incomplete
        // class), whereas primitives always do.
        $data = Cache::remember(
            'link_card:'.sha1($url),
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): ?array => $this->build($url)?->toArray(),
        );

        return $data === null ? null : LinkCardMetadata::fromArray($data);
    }

    private function build(string $url): ?LinkCardMetadata
    {
        $response = $this->http->tryGet($url);

        if ($response === null) {
            return null;
        }

        $meta = $this->extractor->extract($response->body(), $url);
        $title = data_get($meta, 'title');
        $description = data_get($meta, 'description');

        if ($title === null && $description === null) {
            return null;
        }

        return new LinkCardMetadata(
            uri: $url,
            title: $title ?? '',
            description: $description ?? '',
            imageUrl: data_get($meta, 'image'),
        );
    }
}
