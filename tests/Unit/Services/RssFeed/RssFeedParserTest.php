<?php

declare(strict_types=1);

use App\Enums\RssFeed\Format;
use App\Exceptions\RssFeed\InvalidRssFeedException;
use App\Services\RssFeed\RssFeedParser;

const RSS_FEED_PARSER_TEST_ANNOUNCEKIT_BASE = 'https://changelog.announcekit.app/rss.xml';
const RSS_FEED_PARSER_TEST_KB_URL = 'https://changelog.announcekit.app/knowledge-base-beta-is-here---want-in-j4YNy';
const RSS_FEED_PARSER_TEST_KB_IMAGE = 'https://img.announcekit.app/ebff770ecce2db3fb0af61c177f52901?s=c1aafb253bb356c315969b190b9d085f';

function rssFeedParserTestFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/feeds/{$name}"));
}

function rssFeedParserTestParse(string $name, string $baseUrl = 'https://example.com/feed.xml'): mixed
{
    return app(RssFeedParser::class)->parse(rssFeedParserTestFixture($name), $baseUrl);
}

test('parses the AnnounceKit RSS feed', function () {
    $feed = rssFeedParserTestParse('announcekit_rss.xml', RSS_FEED_PARSER_TEST_ANNOUNCEKIT_BASE);
    [$first, $second, $third] = $feed->items;

    expect($feed->format)->toBe(Format::Rss)
        ->and($feed->title)->toBe('AnnounceKit Product Updates')
        ->and($feed->siteUrl)->toBe('https://changelog.announcekit.app')
        ->and($feed->items)->toHaveCount(3)
        ->and($first->guid)->toBe('445968')
        ->and($first->url)->toBe(RSS_FEED_PARSER_TEST_KB_URL)
        ->and($first->title)->toBe('🚀 Knowledge Base beta is here - want in?')
        ->and($first->imageUrl)->toBe(RSS_FEED_PARSER_TEST_KB_IMAGE)
        ->and($first->author)->toBeNull()
        ->and($first->publishedAt->toIso8601String())->toBe('2026-08-13T18:05:36+00:00')
        ->and($first->publishedAt->timezoneName)->toBe('UTC')
        ->and($second->imageUrl)->toBeNull()
        ->and($third->imageUrl)->toBeNull();

    foreach ($feed->items as $item) {
        expect($item->excerpt)->not->toEndWith('...')
            ->and(mb_strlen($item->excerpt))->toBeLessThanOrEqual(300)
            ->and($item->excerpt)->not->toContain('<')
            ->and($item->excerpt)->not->toContain('cta-button')
            ->and($item->excerpt)->not->toContain('&amp;');
    }

    expect($first->excerpt)->toStartWith('Your changelog just got a new best friend. 👀 We’re launching the Knowledge Base, a new way')
        ->and(mb_strlen($first->excerpt))->toBeGreaterThan(143);
});

test('parses the AnnounceKit Atom feed', function () {
    $feed = rssFeedParserTestParse('announcekit_atom.xml', 'https://changelog.announcekit.app/atom.xml');
    $first = $feed->items[0];

    expect($feed->format)->toBe(Format::Atom)
        ->and($feed->title)->toBe('AnnounceKit Product Updates')
        ->and($feed->siteUrl)->toBe('https://changelog.announcekit.app')
        ->and($feed->items)->toHaveCount(3)
        ->and($first->guid)->toBe('445968')
        ->and($first->url)->toBe(RSS_FEED_PARSER_TEST_KB_URL)
        ->and($first->title)->toBe('🚀 Knowledge Base beta is here - want in?')
        ->and($first->publishedAt->toIso8601ZuluString('millisecond'))->toBe('2026-08-13T18:05:36.125Z')
        ->and($first->excerpt)->not->toEndWith('...')
        ->and(mb_strlen($first->excerpt))->toBeGreaterThan(143)
        ->and($first->imageUrl)->toBe(RSS_FEED_PARSER_TEST_KB_IMAGE)
        ->and($feed->items[1]->imageUrl)->toBeNull();
});

test('prefers published, rel alternate and the entry author in Atom', function () {
    $feed = rssFeedParserTestParse('atom_published.xml', 'https://example.net/atom.xml');
    [$first, $second] = $feed->items;

    expect($feed->siteUrl)->toBe('https://example.net/')
        ->and($first->title)->toBe('Published wins')
        ->and($first->url)->toBe('https://example.net/entries/one')
        ->and($first->guid)->toBe('tag:example.net,2026:1')
        ->and($first->publishedAt->toIso8601String())->toBe('2026-09-10T09:00:00+00:00')
        ->and($first->author)->toBe('Grace Hopper')
        ->and($first->imageUrl)->toBe('https://example.net/one.jpg')
        ->and($first->excerpt)->toBe('Short summary.')
        ->and($second->author)->toBe('Feed Author')
        ->and($second->publishedAt->toIso8601String())->toBe('2026-09-16T09:00:00+00:00')
        ->and($second->excerpt)->toBe('XHTML content');
});

test('parses the AnnounceKit JSON Feed', function () {
    $feed = rssFeedParserTestParse('announcekit_jsonfeed.json', 'https://changelog.announcekit.app/jsonfeed.json');
    $first = $feed->items[0];

    expect($feed->format)->toBe(Format::JsonFeed)
        ->and($feed->title)->toBe('AnnounceKit Product Updates')
        ->and($feed->siteUrl)->toBe('https://changelog.announcekit.app')
        ->and($feed->items)->toHaveCount(3)
        ->and($first->guid)->toBe('445968')
        ->and($first->url)->toBe(RSS_FEED_PARSER_TEST_KB_URL)
        ->and($first->publishedAt->toIso8601ZuluString('millisecond'))->toBe('2026-08-13T18:05:36.125Z')
        ->and($first->excerpt)->not->toEndWith('...')
        ->and(mb_strlen($first->excerpt))->toBeGreaterThan(143)
        ->and($first->imageUrl)->toBe(RSS_FEED_PARSER_TEST_KB_IMAGE)
        ->and($feed->items[1]->imageUrl)->toBeNull();
});

test('maps JSON Feed 1.1 images, authors, external urls and missing titles', function () {
    $feed = rssFeedParserTestParse('jsonfeed_1_1.json', 'https://example.io/feed.json');
    [$first, $second, $third] = $feed->items;

    expect($feed->items)->toHaveCount(3)
        ->and($first->imageUrl)->toBe('https://example.io/image-1.jpg')
        ->and($first->author)->toBe('Katherine Johnson')
        ->and($first->publishedAt->toIso8601String())->toBe('2026-09-05T08:00:00+00:00')
        ->and($second->imageUrl)->toBe('https://example.io/banner-2.jpg')
        ->and($second->url)->toBe('https://elsewhere.example.com/story')
        ->and($second->author)->toBe('Legacy Author')
        ->and($second->publishedAt)->toBeNull()
        ->and($third->imageUrl)->toBeNull()
        ->and($third->title)->toBe(mb_substr($third->excerpt, 0, 80))
        ->and(mb_strlen($third->title))->toBe(80);
});

test('parses RSS 1.0 with Dublin Core fields', function () {
    $feed = rssFeedParserTestParse('rss1.xml', 'https://example.org/index.rdf');
    $item = $feed->items[0];

    expect($feed->format)->toBe(Format::Rss)
        ->and($feed->title)->toBe('Example RDF Feed')
        ->and($feed->siteUrl)->toBe('https://example.org/')
        ->and($item->title)->toBe('First RDF post')
        ->and($item->url)->toBe('https://example.org/posts/first')
        ->and($item->guid)->toBe('https://example.org/posts/first')
        ->and($item->excerpt)->toBe('A plain & simple description.')
        ->and($item->author)->toBe('Ada Lovelace')
        ->and($item->publishedAt->toIso8601String())->toBe('2026-09-01T08:00:00+00:00');
});

test('picks images in media, enclosure, inline order and resolves relative urls', function () {
    $feed = rssFeedParserTestParse('media_rss.xml');
    [$a, $b, $c, $d, $e] = $feed->items;

    expect($feed->siteUrl)->toBe('https://example.com/')
        ->and($a->imageUrl)->toBe('https://cdn.example.com/a-thumb.jpg')
        ->and($a->guid)->toBe('item-a')
        ->and($b->imageUrl)->toBe('https://cdn.example.com/b-content.jpg')
        ->and($c->imageUrl)->toBe('https://cdn.example.com/c-enclosure.png')
        ->and($d->imageUrl)->toBe('https://example.com/images/d.png')
        ->and($d->url)->toBe('https://example.com/posts/d')
        ->and($d->guid)->toBe('https://example.com/posts/d')
        ->and($e->imageUrl)->toBeNull()
        ->and($e->url)->toBeNull()
        ->and($e->guid)->toBeNull()
        ->and(mb_strlen($e->title))->toBe(255);
});

test('detects the format from the body whatever the content type said', function (string $fixture, Format $format) {
    expect(rssFeedParserTestParse($fixture)->format)->toBe($format);
})->with([
    'json feed' => ['announcekit_jsonfeed.json', Format::JsonFeed],
    'rss' => ['announcekit_rss.xml', Format::Rss],
    'atom' => ['announcekit_atom.xml', Format::Atom],
]);

test('accepts a UTF-8 BOM and leading whitespace', function () {
    $body = "\xEF\xBB\xBF\n  ".rssFeedParserTestFixture('announcekit_rss.xml');

    expect(app(RssFeedParser::class)->parse($body, RSS_FEED_PARSER_TEST_ANNOUNCEKIT_BASE)->items)->toHaveCount(3);
});

test('never expands an external entity', function () {
    $feed = rssFeedParserTestParse('xxe.xml');
    $item = $feed->items[0];

    expect($feed->title)->toBe('XXE')
        ->and($item->title)->toBe('Leak')
        ->and($item->excerpt)->toBe('Body')
        ->and($item->title.$item->excerpt.$feed->title)->not->toContain('root:');
});

test('rejects an entity expansion bomb', function () {
    rssFeedParserTestParse('billion_laughs.xml');
})->throws(InvalidRssFeedException::class);

test('rejects bodies that are not a feed', function (string $fixture) {
    rssFeedParserTestParse($fixture);
})->with([
    'malformed.xml',
    'malformed.json',
    'json_not_a_feed.json',
    'not_a_feed.html',
])->throws(InvalidRssFeedException::class);

test('rejects plain text and other XML roots', function (string $body) {
    app(RssFeedParser::class)->parse($body, 'https://example.com/');
})->with([
    'plain text' => 'hello world',
    'other xml' => '<?xml version="1.0"?><sitemap><url/></sitemap>',
    'foreign feed element' => '<feed xmlns="https://example.com/not-atom"><title>x</title></feed>',
    'json array' => '[1, 2, 3]',
])->throws(InvalidRssFeedException::class);

test('discovers the first feed link in document order', function () {
    expect(app(RssFeedParser::class)->discover(rssFeedParserTestFixture('html_with_alternate.html'), 'https://blog.example.com/posts/'))
        ->toBe('https://blog.example.com/feed.xml');
});

test('discovers a plain application/json alternate', function () {
    expect(app(RssFeedParser::class)->discover(rssFeedParserTestFixture('html_with_json_alternate.html'), 'https://changelog.example.com/'))
        ->toBe('https://changelog.example.com/jsonfeed.json');
});

test('discovers each feed link type', function (string $type) {
    $html = "<html><head><link rel=\"alternate\" type=\"{$type}\" href=\"feed\"></head></html>";

    expect(app(RssFeedParser::class)->discover($html, 'https://example.com/blog/'))->toBe('https://example.com/blog/feed');
})->with([
    'application/rss+xml',
    'application/atom+xml',
    'application/feed+json',
    'application/json',
]);

test('discovers nothing on a page without a feed link', function () {
    expect(app(RssFeedParser::class)->discover(rssFeedParserTestFixture('not_a_feed.html'), 'https://example.com/'))->toBeNull();
});

test('casts a numeric JSON Feed id to a string and ignores a string tags field', function () {
    $body = json_encode([
        'version' => 'https://jsonfeed.org/version/1',
        'title' => 'Quirks',
        'items' => [
            ['id' => 445968, 'url' => 'https://example.com/a', 'title' => 'Numeric id', 'tags' => '[]', 'content_html' => '<p>Body</p>'],
        ],
    ]);

    $item = app(RssFeedParser::class)->parse($body, 'https://example.com/feed.json')->items[0];

    expect($item->guid)->toBe('445968')
        ->and($item->title)->toBe('Numeric id')
        ->and($item->excerpt)->toBe('Body');
});

test('drops urls longer than the column allows', function () {
    $long = 'https://example.com/'.str_repeat('a', 2048);
    $body = <<<XML
        <?xml version="1.0"?>
        <rss version="2.0"><channel><title>Long</title><link>{$long}</link>
            <item><title>Long link</title><guid>g-1</guid><link>{$long}</link><enclosure url="{$long}.jpg" type="image/jpeg"/><description><![CDATA[<img src="https://example.com/ok.jpg">Body]]></description></item>
            <item><title>Ok link</title><link>https://example.com/ok</link></item>
        </channel></rss>
        XML;

    $feed = app(RssFeedParser::class)->parse($body, 'https://example.com/feed.xml');
    [$long, $ok] = $feed->items;

    expect($feed->siteUrl)->toBeNull()
        ->and($long->url)->toBeNull()
        ->and($long->guid)->toBe('g-1')
        ->and($long->imageUrl)->toBe('https://example.com/ok.jpg')
        ->and($ok->url)->toBe('https://example.com/ok');
});
