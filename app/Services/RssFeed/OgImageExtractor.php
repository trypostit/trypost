<?php

declare(strict_types=1);

namespace App\Services\RssFeed;

use DOMDocument;
use DOMElement;
use Symfony\Component\DomCrawler\UriResolver;

final class OgImageExtractor
{
    private const array PROPERTIES = [
        ['og:image', 'og:image:secure_url', 'og:image:url'],
        ['twitter:image', 'twitter:image:src'],
    ];

    public function extract(string $html, string $pageUrl): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        try {
            $loaded = $document->loadHTML(
                "<?xml encoding=\"UTF-8\">{$html}",
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return null;
        }

        $metas = collect($document->getElementsByTagName('meta'))
            ->filter(fn ($node): bool => $node instanceof DOMElement)
            ->map(fn (DOMElement $meta): array => [
                'key' => strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name'))),
                'content' => trim($meta->getAttribute('content')),
            ])
            ->filter(fn (array $meta): bool => data_get($meta, 'content') !== '');

        foreach (self::PROPERTIES as $keys) {
            foreach ($metas->filter(fn (array $meta): bool => in_array(data_get($meta, 'key'), $keys, true)) as $meta) {
                $url = $this->secureUrl(data_get($meta, 'content'), $pageUrl);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function secureUrl(string $value, string $pageUrl): ?string
    {
        $resolved = UriResolver::resolve(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $pageUrl);

        if (! str_starts_with(strtolower($resolved), 'https://') || mb_strlen($resolved) > RssFeedItemNormalizer::MAX_URL_LENGTH) {
            return null;
        }

        return $resolved;
    }
}
