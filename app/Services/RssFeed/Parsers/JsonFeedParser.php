<?php

declare(strict_types=1);

namespace App\Services\RssFeed\Parsers;

use App\Enums\RssFeed\Format;
use App\Exceptions\RssFeed\InvalidRssFeedException;
use App\Services\RssFeed\ParsedRssFeed;
use App\Services\RssFeed\RssFeedItemNormalizer;
use JsonException;

final class JsonFeedParser
{
    private const string VERSION_PREFIX = 'https://jsonfeed.org/version/1';

    public function __construct(private readonly RssFeedItemNormalizer $normalizer) {}

    public function parse(string $body, string $baseUrl): ParsedRssFeed
    {
        try {
            $feed = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidRssFeedException;
        }

        $version = data_get($feed, 'version');
        $items = data_get($feed, 'items');

        if (! is_array($feed) || ! is_string($version) || ! str_starts_with($version, self::VERSION_PREFIX) || ! is_array($items)) {
            throw new InvalidRssFeedException;
        }

        $parsed = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized = $this->normalizer->item($this->raw($item), $baseUrl);

            if ($normalized !== null) {
                $parsed[] = $normalized;
            }
        }

        return new ParsedRssFeed(
            format: Format::JsonFeed,
            title: $this->normalizer->feedTitle($this->string($feed, 'title'), $baseUrl),
            siteUrl: $this->normalizer->absoluteUrl($this->string($feed, 'home_page_url'), $baseUrl),
            items: $parsed,
        );
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, mixed>
     */
    private function raw(array $item): array
    {
        $id = data_get($item, 'id');
        $contentHtml = $this->string($item, 'content_html');

        return [
            'guid' => is_int($id) || is_float($id) ? (string) $id : (is_string($id) ? $id : null),
            'title' => $this->string($item, 'title'),
            'url' => $this->string($item, 'url') ?? $this->string($item, 'external_url'),
            'html' => $contentHtml,
            'text' => $this->string($item, 'content_text') ?? $this->string($item, 'summary'),
            'image_candidates' => [$this->string($item, 'image'), $this->string($item, 'banner_image')],
            'author' => $this->string($item, 'authors.0.name') ?? $this->string($item, 'author.name'),
            'dates' => [$this->string($item, 'date_published'), $this->string($item, 'date_modified')],
        ];
    }

    private function string(mixed $data, string $key): ?string
    {
        $value = data_get($data, $key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
