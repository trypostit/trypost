<?php

declare(strict_types=1);

use App\DataTransferObjects\MediaItem;

test('fromArray backfills the mime type from the path extension when missing', function () {
    expect(MediaItem::fromArray(['path' => 'a/b/photo.JPG'])->mime_type)->toBe('image/jpeg');
    expect(MediaItem::fromArray(['path' => 'clip.mp4'])->mime_type)->toBe('video/mp4');
    expect(MediaItem::fromArray(['path' => 'clip.mov'])->mime_type)->toBe('video/quicktime');
    expect(MediaItem::fromArray(['path' => 'deck.pdf'])->mime_type)->toBe('application/pdf');
    expect(MediaItem::fromArray(['path' => 'archive.zip'])->mime_type)->toBeNull();
});

test('fromArray coerces a numeric media id to a string', function () {
    $item = MediaItem::fromArray([
        'id' => 42,
        'path' => 'generated.png',
        'url' => 'https://cdn.example.com/generated.png',
    ]);

    expect($item->id)->toBe('42');
});

test('fromArray keeps an explicit mime type over the extension', function () {
    $item = MediaItem::fromArray(['path' => 'thing.png', 'mime_type' => 'video/mp4']);

    expect($item->mime_type)->toBe('video/mp4');
});

test('media item classifies its type via the Type enum', function () {
    expect(MediaItem::fromArray(['path' => 'x.png'])->isImage())->toBeTrue();
    expect(MediaItem::fromArray(['path' => 'x.mp4'])->isVideo())->toBeTrue();
    expect(MediaItem::fromArray(['path' => 'x.pdf'])->isDocument())->toBeTrue();

    $pdf = MediaItem::fromArray(['path' => 'x.pdf']);
    expect($pdf->isImage())->toBeFalse();
    expect($pdf->isVideo())->toBeFalse();
});

test('media item falls back to the extension when no mime is present', function () {
    // A stored heic photo (not in the upload allow-list) still classifies as an image.
    $item = new MediaItem(id: '1', path: 'photo.heic', url: 'https://x/p.heic', mime_type: null);

    expect($item->isImage())->toBeTrue();
});

test('fromArray reads pixel dimensions from the meta block', function () {
    $item = MediaItem::fromArray([
        'path' => 'photo.jpg',
        'url' => 'https://x/photo.jpg',
        'meta' => ['width' => 1254, 'height' => 836],
    ]);

    expect($item->width())->toBe(1254)
        ->and($item->height())->toBe(836);
});

test('width and height are null when no meta is present', function () {
    $item = MediaItem::fromArray(['path' => 'photo.jpg', 'url' => 'https://x/photo.jpg']);

    expect($item->width())->toBeNull()
        ->and($item->height())->toBeNull();
});

test('width and height ignore non-numeric meta values', function () {
    $item = MediaItem::fromArray([
        'path' => 'photo.jpg',
        'url' => 'https://x/photo.jpg',
        'meta' => ['width' => 'wide', 'height' => null],
    ]);

    expect($item->width())->toBeNull()
        ->and($item->height())->toBeNull();
});

test('numeric string dimensions are coerced to integers', function () {
    $item = MediaItem::fromArray([
        'path' => 'photo.jpg',
        'url' => 'https://x/photo.jpg',
        'meta' => ['width' => '1080', 'height' => '1920'],
    ]);

    expect($item->width())->toBe(1080)
        ->and($item->height())->toBe(1920);
});
