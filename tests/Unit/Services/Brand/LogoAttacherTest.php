<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Services\Brand\LogoAttacher;
use Illuminate\Support\Facades\Http;

// A public IP literal as the host lets SafeHttpFetcher's SSRF guard pass without
// a real DNS lookup; Http::fake() intercepts the request before any network I/O.
const LOGO_URL = 'https://93.184.216.34/logo.png';

function fakeLogo(string $mime = 'image/png', string $body = 'PNG'): void
{
    Http::fake(['*' => Http::response($body, 200, [
        'Content-Type' => $mime,
        'Content-Length' => (string) strlen($body),
    ])]);
}

test('attaches the downloaded logo and returns true', function () {
    fakeLogo();

    $workspace = mock(Workspace::class);
    $workspace->shouldReceive('clearMediaCollection')->once();
    $workspace->shouldReceive('addMediaFromPath')->once();

    expect(app(LogoAttacher::class)->attach($workspace, LOGO_URL))->toBeTrue();
});

test('swallows a persistence failure and returns false', function () {
    fakeLogo();

    $workspace = mock(Workspace::class);
    $workspace->shouldReceive('clearMediaCollection')->once()->andThrow(new RuntimeException('media store down'));

    expect(app(LogoAttacher::class)->attach($workspace, LOGO_URL))->toBeFalse();
});

test('returns false when the fetch fails', function () {
    Http::fake(['*' => Http::response('', 500)]);

    expect(app(LogoAttacher::class)->attach(mock(Workspace::class), LOGO_URL))->toBeFalse();
});

test('rejects a disallowed mime type and returns false', function () {
    fakeLogo('text/html', '<html></html>');

    $workspace = mock(Workspace::class);
    $workspace->shouldNotReceive('addMediaFromPath');

    expect(app(LogoAttacher::class)->attach($workspace, LOGO_URL))->toBeFalse();
});
