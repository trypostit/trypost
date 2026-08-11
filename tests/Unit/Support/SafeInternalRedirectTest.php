<?php

declare(strict_types=1);

use App\Support\SafeInternalRedirect;

test('resolve allows an internal path', function () {
    expect(SafeInternalRedirect::resolve('/invites/abc-123'))->toBe('/invites/abc-123');
});

test('resolve rejects an absolute external url', function () {
    expect(SafeInternalRedirect::resolve('https://evil.example.com'))->toBeNull();
});

test('resolve rejects a protocol-relative url', function () {
    expect(SafeInternalRedirect::resolve('//evil.example.com'))->toBeNull();
});

test('resolve rejects non-string and empty values', function () {
    expect(SafeInternalRedirect::resolve(null))->toBeNull()
        ->and(SafeInternalRedirect::resolve(''))->toBeNull()
        ->and(SafeInternalRedirect::resolve(123))->toBeNull()
        ->and(SafeInternalRedirect::resolve(['/foo']))->toBeNull();
});
