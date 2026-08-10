<?php

declare(strict_types=1);

use App\Enums\Pinterest\MediaUploadStatus;

test('media upload status mirrors the pinterest api enum values', function () {
    expect(MediaUploadStatus::cases())->toHaveCount(4)
        ->and(MediaUploadStatus::Registered->value)->toBe('registered')
        ->and(MediaUploadStatus::Processing->value)->toBe('processing')
        ->and(MediaUploadStatus::Succeeded->value)->toBe('succeeded')
        ->and(MediaUploadStatus::Failed->value)->toBe('failed');
});

test('media upload status tryFrom accepts known values and rejects unknown', function (string $value, ?MediaUploadStatus $expected) {
    expect(MediaUploadStatus::tryFrom($value))->toBe($expected);
})->with([
    ['registered', MediaUploadStatus::Registered],
    ['processing', MediaUploadStatus::Processing],
    ['succeeded', MediaUploadStatus::Succeeded],
    ['failed', MediaUploadStatus::Failed],
    ['unknown', null],
    ['', null],
]);
