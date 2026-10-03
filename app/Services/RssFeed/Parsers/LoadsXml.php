<?php

declare(strict_types=1);

namespace App\Services\RssFeed\Parsers;

use App\Exceptions\RssFeed\InvalidRssFeedException;
use SimpleXMLElement;

trait LoadsXml
{
    private const string ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    private const string RSS1_NAMESPACE = 'http://purl.org/rss/1.0/';

    private const string CONTENT_NAMESPACE = 'http://purl.org/rss/1.0/modules/content/';

    private const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    private const string MEDIA_NAMESPACE = 'http://search.yahoo.com/mrss/';

    private function loadXml(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            throw new InvalidRssFeedException;
        }

        return $document;
    }

    private function text(?SimpleXMLElement $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $value = trim((string) $element);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<?string>
     */
    private function mediaImages(SimpleXMLElement $item): array
    {
        $media = $item->children(self::MEDIA_NAMESPACE);
        $candidates = [];

        foreach ([$media->thumbnail, $media->group?->thumbnail] as $thumbnails) {
            foreach ($thumbnails ?? [] as $thumbnail) {
                $candidates[] = (string) $thumbnail->attributes()->url;
            }
        }

        foreach ([$media->content, $media->group?->content] as $contents) {
            foreach ($contents ?? [] as $content) {
                $attributes = $content->attributes();
                $medium = strtolower((string) $attributes->medium);
                $type = strtolower((string) $attributes->type);

                if ($medium === 'image' || str_starts_with($type, 'image/')) {
                    $candidates[] = (string) $attributes->url;
                }
            }
        }

        return $candidates;
    }
}
