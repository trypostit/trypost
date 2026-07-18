<?php

declare(strict_types=1);

use App\Services\Social\LinkCard\OpenGraphExtractor;

test('extracts open graph title, description and absolute image', function () {
    $html = <<<'HTML'
    <html><head>
        <meta property="og:title" content="Hello OG">
        <meta property="og:description" content="A description">
        <meta property="og:image" content="/img/card.png">
    </head><body></body></html>
    HTML;

    $meta = (new OpenGraphExtractor)->extract($html, 'https://example.com/post');

    expect($meta['title'])->toBe('Hello OG')
        ->and($meta['description'])->toBe('A description')
        ->and($meta['image'])->toBe('https://example.com/img/card.png');
});

test('falls back to title tag and meta description', function () {
    $html = '<html><head><title>Plain Title</title>'
        .'<meta name="description" content="Meta desc"></head><body></body></html>';

    $meta = (new OpenGraphExtractor)->extract($html, 'https://example.com');

    expect($meta['title'])->toBe('Plain Title')
        ->and($meta['description'])->toBe('Meta desc')
        ->and($meta['image'])->toBeNull();
});

test('returns nulls when nothing is present', function () {
    $meta = (new OpenGraphExtractor)->extract('<html><body>no meta</body></html>', 'https://example.com');

    expect($meta)->toBe(['title' => null, 'description' => null, 'image' => null]);
});
