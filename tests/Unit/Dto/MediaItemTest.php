<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Models\Media;
use App\Models\Workspace;

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

test('fromMedia builds the stored post media item and carries the measured meta', function () {
    $workspace = Workspace::factory()->create();
    $video = Media::factory()->video()->for($workspace, 'mediable')->create(['meta' => ['duration' => 12.5]]);

    expect(MediaItem::fromMedia($video, 'ignored on video')->toArray())->toEqual([
        'id' => $video->id,
        'path' => $video->path,
        'url' => $video->url,
        'type' => 'video',
        'mime_type' => 'video/mp4',
        'original_filename' => $video->original_filename,
        'size' => $video->size,
        'meta' => ['duration' => 12.5],
    ]);
});

test('fromMedia puts alt text in meta for images only and omits an empty meta', function () {
    $workspace = Workspace::factory()->create();
    $image = Media::factory()->for($workspace, 'mediable')->create(['meta' => ['width' => 10, 'height' => 20]]);
    $document = Media::factory()->document()->for($workspace, 'mediable')->create();

    expect(MediaItem::fromMedia($image, 'A red bicycle')->meta)->toEqual(['width' => 10, 'height' => 20, 'alt_text' => 'A red bicycle'])
        ->and(MediaItem::fromMedia($image, '')->meta)->toEqual(['width' => 10, 'height' => 20])
        ->and(MediaItem::fromMedia($document, 'ignored on pdf')->toArray())->not->toHaveKey('meta');
});

test('the stored type wins over a contradicting mime, and older items without one classify by mime then path', function () {
    $typed = MediaItem::fromArray(['type' => 'image', 'mime_type' => 'video/mp4', 'path' => 'medias/clip.mp4']);
    $byMime = MediaItem::fromArray(['mime_type' => 'video/mp4', 'path' => 'medias/photo.jpg']);
    $byPath = MediaItem::fromArray(['path' => 'https://cdn.example.com/medias/clip.mov?sig=1']);

    expect($typed->isImage())->toBeTrue()
        ->and($typed->isVideo())->toBeFalse()
        ->and($byMime->isVideo())->toBeTrue()
        ->and($byPath->isVideo())->toBeTrue()
        ->and($byPath->mime_type)->toBe('video/quicktime');
});

test('a stored item round-trips through fromArray and toArray', function () {
    $stored = [
        'id' => 'abc',
        'path' => 'medias/photo.jpg',
        'url' => 'https://cdn.example.com/photo.jpg',
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        'original_filename' => 'photo.jpg',
        'size' => 1234,
        'meta' => ['width' => 10, 'height' => 20],
        'source' => 'unsplash',
        'source_meta' => ['photographer' => 'Ana'],
    ];

    expect(MediaItem::fromArray($stored)->toArray())->toEqual($stored);
});
