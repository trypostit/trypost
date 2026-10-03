<?php

declare(strict_types=1);

namespace App\Services\RssFeed\Parsers;

use App\Enums\RssFeed\Format;
use App\Services\RssFeed\ParsedRssFeed;
use App\Services\RssFeed\RssFeedItemNormalizer;
use SimpleXMLElement;

final class RssParser
{
    use LoadsXml;

    public function __construct(private readonly RssFeedItemNormalizer $normalizer) {}

    public function parseDocument(SimpleXMLElement $document, string $baseUrl): ParsedRssFeed
    {
        $isRdf = $document->getName() === 'RDF';
        $root = $isRdf ? $document->children(self::RSS1_NAMESPACE) : $document->children();
        $channel = $root->channel;
        $items = ($isRdf ? $root->item : $channel?->item) ?? [];

        $parsed = [];

        foreach ($items as $item) {
            $normalized = $this->normalizer->item($this->raw($isRdf ? $item->children(self::RSS1_NAMESPACE) : $item->children(), $item), $baseUrl);

            if ($normalized !== null) {
                $parsed[] = $normalized;
            }
        }

        return new ParsedRssFeed(
            format: Format::Rss,
            title: $this->normalizer->feedTitle($this->text($channel?->title), $baseUrl),
            siteUrl: $this->normalizer->absoluteUrl($this->text($channel?->link), $baseUrl),
            items: $parsed,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(SimpleXMLElement $fields, SimpleXMLElement $item): array
    {
        $dc = $item->children(self::DC_NAMESPACE);
        $encoded = $this->text($item->children(self::CONTENT_NAMESPACE)->encoded);

        $candidates = $this->mediaImages($item);

        foreach ($fields->enclosure ?? [] as $enclosure) {
            $attributes = $enclosure->attributes();

            if (str_starts_with(strtolower((string) $attributes->type), 'image/')) {
                $candidates[] = (string) $attributes->url;
            }
        }

        return [
            'guid' => $this->text($fields->guid),
            'title' => $this->text($fields->title),
            'url' => $this->text($fields->link),
            'html' => $encoded ?? $this->text($fields->description),
            'text' => null,
            'image_candidates' => $candidates,
            'author' => $this->text($fields->author) ?? $this->text($dc->creator),
            'dates' => [$this->text($fields->pubDate), $this->text($dc->date)],
        ];
    }
}
