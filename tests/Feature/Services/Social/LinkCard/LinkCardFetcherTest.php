<?php

declare(strict_types=1);

use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Services\Social\LinkCard\LinkCardMetadata;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// example.com resolves to a public IP so the SafeHttpFetcher SSRF guard passes;
// Http::fake then intercepts the actual request.
test('fetches a card from the first url in the text', function () {
    Http::fake([
        'https://example.com' => Http::response(
            '<html><head><meta property="og:title" content="Example Title">'
            .'<meta property="og:description" content="Example description">'
            .'<meta property="og:image" content="https://example.com/card.png">'
            .'</head></html>',
            200,
        ),
    ]);

    $card = app(LinkCardFetcher::class)->fetch('look at https://example.com today');

    expect($card)->not->toBeNull()
        ->and($card->uri)->toBe('https://example.com')
        ->and($card->title)->toBe('Example Title')
        ->and($card->description)->toBe('Example description')
        ->and($card->imageUrl)->toBe('https://example.com/card.png');
});

test('returns null when the text has no url', function () {
    expect(app(LinkCardFetcher::class)->fetch('no links here'))->toBeNull();
});

test('returns null for a private-network url (ssrf guard)', function () {
    expect(app(LinkCardFetcher::class)->fetch('internal http://127.0.0.1/admin'))->toBeNull();
});

test('returns null when the page has no title or description', function () {
    Http::fake(['https://example.com' => Http::response('<html><body>nothing</body></html>', 200)]);

    expect(app(LinkCardFetcher::class)->fetch('see https://example.com'))->toBeNull();
});

test('caches the result so a repeated url is fetched once', function () {
    Http::fake([
        'https://example.com' => Http::response(
            '<html><head><meta property="og:title" content="Cached"></head></html>',
            200,
        ),
    ]);

    $fetcher = app(LinkCardFetcher::class);
    $first = $fetcher->fetch('https://example.com');
    $second = $fetcher->fetch('https://example.com');

    Http::assertSentCount(1);

    // The cache must hold a primitive array, not the DTO — a rich object does
    // not round-trip through every cache driver (it comes back as an incomplete
    // class). A cache hit still reconstructs a proper DTO.
    expect(Cache::get('link_card:'.sha1('https://example.com')))->toBeArray();
    expect($second)->toBeInstanceOf(LinkCardMetadata::class)
        ->and($second->uri)->toBe($first->uri)
        ->and($second->title)->toBe('Cached');
});
