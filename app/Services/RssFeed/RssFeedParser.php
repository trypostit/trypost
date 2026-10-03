<?php

declare(strict_types=1);

namespace App\Services\RssFeed;

use App\Exceptions\RssFeed\InvalidRssFeedException;
use App\Services\RssFeed\Parsers\AtomParser;
use App\Services\RssFeed\Parsers\JsonFeedParser;
use App\Services\RssFeed\Parsers\LoadsXml;
use App\Services\RssFeed\Parsers\RssParser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

final class RssFeedParser
{
    use LoadsXml;

    private const array DISCOVERABLE_TYPES = [
        'application/rss+xml',
        'application/atom+xml',
        'application/feed+json',
        'application/json',
    ];

    public function __construct(
        private readonly RssParser $rss,
        private readonly AtomParser $atom,
        private readonly JsonFeedParser $jsonFeed,
    ) {}

    public function parse(string $body, string $baseUrl): ParsedRssFeed
    {
        $trimmed = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body);

        return match (true) {
            str_starts_with($trimmed, '{') => $this->jsonFeed->parse($trimmed, $baseUrl),
            str_starts_with($trimmed, '<') => $this->parseXml($trimmed, $baseUrl),
            default => throw new InvalidRssFeedException,
        };
    }

    public function discover(string $html, string $baseUrl): ?string
    {
        try {
            $links = (new Crawler($html, $baseUrl))->filterXPath('//link[@rel][@href]');
        } catch (Throwable) {
            return null;
        }

        foreach ($links as $link) {
            $rel = array_map('strtolower', preg_split('/\s+/', trim($link->getAttribute('rel'))) ?: []);
            $type = strtolower(trim(explode(';', $link->getAttribute('type'))[0]));
            $href = trim($link->getAttribute('href'));

            if (in_array('alternate', $rel, true) && in_array($type, self::DISCOVERABLE_TYPES, true) && $href !== '') {
                $resolved = UriResolver::resolve($href, $baseUrl);

                if (in_array(strtolower((string) parse_url($resolved, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function parseXml(string $xml, string $baseUrl): ParsedRssFeed
    {
        $document = $this->loadXml($xml);
        $name = $document->getName();

        return match (true) {
            $name === 'rss', $name === 'RDF' => $this->rss->parseDocument($document, $baseUrl),
            $name === 'feed' && in_array(self::ATOM_NAMESPACE, $document->getNamespaces(), true) => $this->atom->parseDocument($document, $baseUrl),
            default => throw new InvalidRssFeedException,
        };
    }
}
