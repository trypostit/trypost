<?php

declare(strict_types=1);

use App\Services\Brand\SafeHttpFetcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

// Public IP literals let SafeHttpFetcher's SSRF guard pass without a real DNS
// lookup; Http::fake intercepts the request before any network I/O.

test('blocks a redirect whose location resolves to a private ip', function () {
    Http::fake([
        // The malicious hop responds successfully (not with an error/exception) so a
        // vulnerable implementation that auto-follows it would return this body —
        // proving the guard, not an unrelated stray-request failure, stopped it.
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/internal' => Http::response('internal secret', 200),
    ]);

    expect(app(SafeHttpFetcher::class)->tryGet('https://93.184.216.34/start'))->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '93.184.216.34'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('follows a legitimate redirect from one public host to another', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 301, ['Location' => 'https://1.1.1.1/final']),
        'https://1.1.1.1/final' => Http::response('final body', 200),
    ]);

    $response = app(SafeHttpFetcher::class)->get('https://93.184.216.34/start');

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe('final body');

    Http::assertSentInOrder([
        fn ($request) => str_contains($request->url(), '93.184.216.34'),
        fn ($request) => str_contains($request->url(), '1.1.1.1'),
    ]);
});

test('resolves a relative location header against the current url before guarding', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => '/final']),
        'https://93.184.216.34/final' => Http::response('final body', 200),
    ]);

    $response = app(SafeHttpFetcher::class)->get('https://93.184.216.34/start');

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe('final body');
});

test('throws when a redirect chain exceeds the redirect cap', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'https://93.184.216.35/hop']),
        'https://93.184.216.35/hop' => Http::response('', 302, ['Location' => 'https://93.184.216.36/hop']),
        'https://93.184.216.36/hop' => Http::response('', 302, ['Location' => 'https://93.184.216.37/hop']),
        'https://93.184.216.37/hop' => Http::response('', 302, ['Location' => 'https://93.184.216.38/hop']),
    ]);

    expect(fn () => app(SafeHttpFetcher::class)->get('https://93.184.216.34/start'))
        ->toThrow(RuntimeException::class);

    expect(app(SafeHttpFetcher::class)->tryGet('https://93.184.216.34/start'))->toBeNull();

    // The cap (3) is hit after following 3 redirects (4 requests); the next hop must never fire.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '93.184.216.38'));
});

test('guardAgainstSsrf blocks a private ip by default', function () {
    expect(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf('http://127.0.0.1/x'))
        ->toThrow(RuntimeException::class);
});

test('guardAgainstSsrf allows a private ip when allow_private_network is enabled', function () {
    config(['trypost.security.allow_private_network' => true]);

    app(SafeHttpFetcher::class)->guardAgainstSsrf('http://127.0.0.1/x');
})->throwsNoExceptions();

test('guardedRequest throws for a private ip by default', function () {
    expect(fn () => app(SafeHttpFetcher::class)->guardedRequest('http://127.0.0.1/x'))
        ->toThrow(RuntimeException::class);
});

test('guardedRequest returns a PendingRequest for a public url', function () {
    expect(app(SafeHttpFetcher::class)->guardedRequest('https://93.184.216.34/x'))
        ->toBeInstanceOf(PendingRequest::class);
});
