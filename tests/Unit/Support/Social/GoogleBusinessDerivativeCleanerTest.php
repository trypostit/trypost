<?php

declare(strict_types=1);

use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Storage;

test('it deletes the managed google business jpeg for a post platform', function () {
    Storage::fake();
    $postPlatformId = '123e4567-e89b-12d3-a456-426614174000';
    $path = GoogleBusinessDerivativeCleaner::pathFor($postPlatformId);
    Storage::put($path, 'image');
    Storage::put('uploads/keep.jpg', 'keep');

    app(GoogleBusinessDerivativeCleaner::class)->cleanup($postPlatformId);

    Storage::assertMissing($path);
    Storage::assertExists('uploads/keep.jpg');
});

test('it no-ops when the derivative is already gone', function () {
    Storage::fake();

    app(GoogleBusinessDerivativeCleaner::class)->cleanup('123e4567-e89b-12d3-a456-426614174000');

    expect(Storage::allFiles())->toBe([]);
});
