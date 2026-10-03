<?php

declare(strict_types=1);

namespace App\Services\RssFeed;

use App\Exceptions\RssFeed\InvalidRssFeedException;
use App\Exceptions\RssFeed\RssFeedFetchException;
use App\Services\Http\FetchedBody;
use App\Services\Http\SafeHttpFetcher;
use Carbon\CarbonInterface;
use RuntimeException;

final class RssFeedFetcher
{
    public function __construct(
        private readonly SafeHttpFetcher $http,
        private readonly RssFeedParser $parser,
    ) {}

    public function fetch(string $url, bool $allowDiscovery = false): FetchedRssFeed
    {
        return $this->fetchWithin(
            $this->http->normalizeUrl($url),
            $allowDiscovery,
            now()->addSeconds((int) config('trypost.rss_feeds.fetch_budget_seconds')),
        );
    }

    private function fetchWithin(string $url, bool $allowDiscovery, CarbonInterface $deadline): FetchedRssFeed
    {
        $fetched = $this->download($url, $deadline);

        try {
            return new FetchedRssFeed($fetched->finalUrl, $this->parser->parse($fetched->body, $fetched->finalUrl));
        } catch (InvalidRssFeedException) {
            $discovered = $allowDiscovery ? $this->parser->discover($fetched->body, $fetched->finalUrl) : null;

            if ($discovered === null) {
                throw new RssFeedFetchException('create.feeds.errors.not_a_feed');
            }

            return $this->fetchWithin($discovered, false, $deadline);
        }
    }

    private function download(string $url, CarbonInterface $deadline): FetchedBody
    {
        if (mb_strlen($url) > RssFeedItemNormalizer::MAX_URL_LENGTH) {
            throw new RssFeedFetchException('create.feeds.errors.unreachable');
        }

        try {
            $this->http->guardAgainstSsrf($url);
        } catch (RuntimeException) {
            throw new RssFeedFetchException('create.feeds.errors.blocked_url');
        }

        $fetched = $this->http->tryFetch($url, (int) config('trypost.rss_feeds.max_response_bytes'), $deadline);

        if ($fetched === null || mb_strlen($fetched->finalUrl) > RssFeedItemNormalizer::MAX_URL_LENGTH) {
            throw new RssFeedFetchException('create.feeds.errors.unreachable');
        }

        return $fetched;
    }
}
