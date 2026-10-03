<?php

declare(strict_types=1);

namespace App\Services\RssFeed;

use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

final class RssFeedItemNormalizer
{
    public const int MAX_URL_LENGTH = 2048;

    private const int MAX_TITLE_LENGTH = 255;

    private const int MAX_EXCERPT_LENGTH = 300;

    private const int FALLBACK_TITLE_LENGTH = 80;

    private const string BLOCK_TAGS = '~<(?:/?(?:p|div|li|ul|ol|h[1-6]|blockquote|pre|tr|td|th|section|article|figure|figcaption|table)\b[^>]*|br\b[^>]*|hr\b[^>]*)>~i';

    /**
     * @param  array{guid?: ?string, title?: ?string, url?: ?string, html?: ?string, text?: ?string, image_candidates?: list<?string>, author?: ?string, dates?: list<?string>}  $raw
     */
    public function item(array $raw, string $baseUrl): ?ParsedRssFeedItem
    {
        $url = $this->absoluteUrl(data_get($raw, 'url'), $baseUrl);
        $html = $this->nonEmpty(data_get($raw, 'html'));
        $excerpt = $html !== null
            ? $this->textFromHtml($html)
            : $this->collapse((string) data_get($raw, 'text', ''));
        $excerpt = $excerpt === '' ? null : $this->truncate($excerpt, self::MAX_EXCERPT_LENGTH, '…');

        $title = $this->collapse($this->decode(strip_tags((string) data_get($raw, 'title', ''))));

        if ($title === '' && $excerpt !== null) {
            $title = rtrim(mb_substr($excerpt, 0, self::FALLBACK_TITLE_LENGTH));
        }

        if ($title === '' && $url === null && $excerpt === null) {
            return null;
        }

        if ($title === '') {
            $title = (string) $url;
        }

        $author = $this->collapse($this->decode(strip_tags((string) data_get($raw, 'author', ''))));

        return new ParsedRssFeedItem(
            guid: $this->nonEmpty(data_get($raw, 'guid')) ?? $url,
            title: mb_substr($title, 0, self::MAX_TITLE_LENGTH),
            url: $url,
            excerpt: $excerpt,
            imageUrl: $this->image((array) data_get($raw, 'image_candidates', []), $html, $baseUrl),
            author: $author === '' ? null : mb_substr($author, 0, self::MAX_TITLE_LENGTH),
            publishedAt: $this->date((array) data_get($raw, 'dates', [])),
        );
    }

    public function feedTitle(?string $title, string $baseUrl): string
    {
        $title = $this->collapse($this->decode(strip_tags((string) $title)));

        if ($title === '') {
            $title = (string) parse_url($baseUrl, PHP_URL_HOST);
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    public function absoluteUrl(mixed $value, string $baseUrl): ?string
    {
        $value = $this->nonEmpty($value);

        if ($value === null) {
            return null;
        }

        $resolved = UriResolver::resolve($value, $baseUrl);
        $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || mb_strlen($resolved) > self::MAX_URL_LENGTH) {
            return null;
        }

        return $resolved;
    }

    /**
     * @param  list<?string>  $candidates
     */
    private function image(array $candidates, ?string $html, string $baseUrl): ?string
    {
        if ($html !== null) {
            $candidates[] = $this->firstImageSource($html);
        }

        foreach ($candidates as $candidate) {
            $url = $this->absoluteUrl($candidate, $baseUrl);

            if ($url !== null && str_starts_with(strtolower($url), 'https://')) {
                return $url;
            }
        }

        return null;
    }

    private function firstImageSource(string $html): ?string
    {
        if (stripos($html, '<img') === false) {
            return null;
        }

        try {
            $crawler = new Crawler;
            $crawler->addHtmlContent("<!DOCTYPE html><html><body>{$html}</body></html>");
            $image = $crawler->filterXPath('//img[@src]')->first();

            return $image->count() === 0 ? null : $this->nonEmpty($image->attr('src'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<?string>  $dates
     */
    private function date(array $dates): ?CarbonImmutable
    {
        foreach ($dates as $date) {
            $date = $this->nonEmpty($date);

            if ($date === null) {
                continue;
            }

            try {
                return CarbonImmutable::parse($date)->utc();
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function textFromHtml(string $html): string
    {
        $html = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $html);
        $html = (string) preg_replace(self::BLOCK_TAGS, ' ', $html);

        return $this->collapse($this->decode(strip_tags($html)));
    }

    private function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    private function truncate(string $value, int $limit, string $end): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - mb_strlen($end))).$end;
    }

    private function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
