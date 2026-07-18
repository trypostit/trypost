<?php

declare(strict_types=1);

namespace App\Services\Social\LinkCard;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * Deterministic OpenGraph reader for link preview cards. Pulls og:title /
 * og:description / og:image with <title> and meta-description fallbacks.
 * Deliberately separate from HomepageMetaExtractor, which is brand-tuned and
 * intentionally excludes og:image.
 */
final class OpenGraphExtractor
{
    /**
     * @return array{title: ?string, description: ?string, image: ?string}
     */
    public function extract(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);

        return [
            'title' => $this->title($crawler),
            'description' => $this->metaContent($crawler, 'property', 'og:description')
                ?? $this->metaContent($crawler, 'name', 'description'),
            'image' => $this->image($crawler, $baseUrl),
        ];
    }

    private function title(Crawler $crawler): ?string
    {
        $ogTitle = $this->metaContent($crawler, 'property', 'og:title');

        if ($ogTitle !== null) {
            return $ogTitle;
        }

        $title = $crawler->filter('title')->first();

        if ($title->count() === 0) {
            return null;
        }

        $text = trim($title->text(''));

        return $text === '' ? null : $text;
    }

    private function image(Crawler $crawler, string $baseUrl): ?string
    {
        $image = $this->metaContent($crawler, 'property', 'og:image');

        if ($image === null) {
            return null;
        }

        return UriResolver::resolve($image, $baseUrl);
    }

    private function metaContent(Crawler $crawler, string $attr, string $value): ?string
    {
        $node = $crawler->filter(sprintf('meta[%s="%s"]', $attr, $value))->first();

        if ($node->count() === 0) {
            return null;
        }

        $content = trim((string) $node->attr('content', ''));

        return $content === '' ? null : $content;
    }
}
