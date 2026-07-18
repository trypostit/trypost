<?php

declare(strict_types=1);

use App\DataTransferObjects\MediaItem;
use App\Enums\SocialAccount\Platform;

test('altText returns the trimmed meta alt_text', function () {
    $item = MediaItem::fromArray(['id' => 'a', 'path' => 'p.jpg', 'url' => 'u', 'meta' => ['alt_text' => '  a cat  ']]);

    expect($item->altText())->toBe('a cat');
});

test('altText is null when meta has no alt_text or it is blank', function () {
    expect(MediaItem::fromArray(['id' => 'a', 'path' => 'p.jpg', 'url' => 'u'])->altText())->toBeNull()
        ->and(MediaItem::fromArray(['id' => 'a', 'path' => 'p.jpg', 'url' => 'u', 'meta' => ['alt_text' => '   ']])->altText())->toBeNull();
});

test('altText is null when meta alt_text is not a string', function () {
    expect(MediaItem::fromArray(['id' => 'a', 'path' => 'p.jpg', 'url' => 'u', 'meta' => ['alt_text' => 123]])->altText())->toBeNull()
        ->and(MediaItem::fromArray(['id' => 'a', 'path' => 'p.jpg', 'url' => 'u', 'meta' => ['alt_text' => ['x']]])->altText())->toBeNull();
});

test('altText keeps the literal string "0"', function () {
    $item = MediaItem::fromArray(['id' => 'a', 'path' => 'p.jpg', 'url' => 'u', 'meta' => ['alt_text' => '0']]);

    expect($item->altText())->toBe('0');
});

test('altTextFor truncates to the platform cap and is null for an unsupported platform', function () {
    $longAlt = str_repeat('a', Platform::X->altTextMaxLength() + 50);
    $item = MediaItem::fromArray(['id' => 'a', 'path' => 'p.jpg', 'url' => 'u', 'meta' => ['alt_text' => $longAlt]]);

    expect($item->altTextFor(Platform::X))->toBe(mb_substr($longAlt, 0, Platform::X->altTextMaxLength()))
        ->and(mb_strlen($item->altTextFor(Platform::X)))->toBe(Platform::X->altTextMaxLength())
        ->and($item->altTextFor(Platform::TikTok))->toBeNull();
});
