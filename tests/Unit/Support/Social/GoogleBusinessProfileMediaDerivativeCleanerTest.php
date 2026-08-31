<?php

declare(strict_types=1);

use App\Support\Social\GoogleBusinessProfileMediaDerivativeCleaner;
use App\Support\Social\PublishCheckpoint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('Google Business Profile derivative cleaner deletes only managed image paths', function (): void {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);

    $managed = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/'.Str::uuid().'.jpg';
    $nested = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/nested/'.Str::uuid().'.jpg';
    $wrongExtension = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/'.Str::uuid().'.webp';
    Storage::put($managed, 'managed');
    Storage::put($nested, 'nested');
    Storage::put($wrongExtension, 'webp');

    $cleaner = app(GoogleBusinessProfileMediaDerivativeCleaner::class);
    $cleaner->cleanup([
        PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => $managed,
    ]);
    $cleaner->cleanupPath($nested);
    $cleaner->cleanupPath($wrongExtension);

    Storage::assertMissing($managed);
    Storage::assertExists($nested);
    Storage::assertExists($wrongExtension);
});

test('Google Business Profile derivative cleaner ignores malformed context', function (): void {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);

    $cleaner = app(GoogleBusinessProfileMediaDerivativeCleaner::class);

    $cleaner->cleanup(null);
    $cleaner->cleanup([PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => ['not-a-path']]);
    $cleaner->cleanupPath('../outside.jpg');

    expect(true)->toBeTrue();
});
