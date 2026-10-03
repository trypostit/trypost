<?php

declare(strict_types=1);

namespace App\Services\RssFeed\Parsers;

use App\Enums\RssFeed\Format;
use App\Services\RssFeed\ParsedRssFeed;
use App\Services\RssFeed\RssFeedItemNormalizer;
use SimpleXMLElement;

final class AtomParser
{
    use LoadsXml;

    public function __construct(private readonly RssFeedItemNormalizer $normalizer) {}

    public function parseDocument(SimpleXMLElement $document, string $baseUrl): ParsedRssFeed
    {
        $feed = $document->children(self::ATOM_NAMESPACE);
        $feedAuthor = $this->text($feed->author?->name);

        $parsed = [];

        foreach ($feed->entry ?? [] as $entry) {
            $normalized = $this->normalizer->item($this->raw($entry, $feedAuthor), $baseUrl);

            if ($normalized !== null) {
                $parsed[] = $normalized;
            }
        }

        return new ParsedRssFeed(
            format: Format::Atom,
            title: $this->normalizer->feedTitle($this->text($feed->title), $baseUrl),
            siteUrl: $this->normalizer->absoluteUrl($this->alternateLink($feed), $baseUrl),
            items: $parsed,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(SimpleXMLElement $entry, ?string $feedAuthor): array
    {
        $fields = $entry->children(self::ATOM_NAMESPACE);
        $candidates = $this->mediaImages($entry);

        foreach ($fields->link ?? [] as $link) {
            $attributes = $link->attributes();

            if (strtolower((string) $attributes->rel) === 'enclosure' && str_starts_with(strtolower((string) $attributes->type), 'image/')) {
                $candidates[] = (string) $attributes->href;
            }
        }

        $content = $this->content($fields->content) ?? $this->content($fields->summary);

        return [
            'guid' => $this->text($fields->id),
            'title' => $this->text($fields->title),
            'url' => $this->alternateLink($fields),
            'html' => data_get($content, 'html'),
            'text' => data_get($content, 'text'),
            'image_candidates' => $candidates,
            'author' => $this->text($fields->author?->name) ?? $feedAuthor,
            'dates' => [$this->text($fields->published), $this->text($fields->updated)],
        ];
    }

    private function alternateLink(SimpleXMLElement $fields): ?string
    {
        $withoutRel = null;

        foreach ($fields->link ?? [] as $link) {
            $attributes = $link->attributes();
            $href = trim((string) $attributes->href);

            if ($href === '') {
                continue;
            }

            if (! isset($attributes->rel)) {
                $withoutRel ??= $href;

                continue;
            }

            if (strtolower(trim((string) $attributes->rel)) === 'alternate') {
                return $href;
            }
        }

        return $withoutRel;
    }

    /**
     * @return array{html: ?string, text: ?string}|null
     */
    private function content(?SimpleXMLElement $element): ?array
    {
        if ($element === null || $element->getName() === '') {
            return null;
        }

        $type = strtolower((string) $element->attributes()->type);

        if ($type === 'xhtml') {
            $node = dom_import_simplexml($element);
            $html = '';

            foreach ($node->childNodes as $child) {
                $html .= (string) $node->ownerDocument?->saveXML($child);
            }

            return trim($html) === '' ? null : ['html' => $html, 'text' => null];
        }

        $value = $this->text($element);

        if ($value === null) {
            return null;
        }

        return $type === 'html' || $type === 'text/html'
            ? ['html' => $value, 'text' => null]
            : ['html' => null, 'text' => $value];
    }
}
