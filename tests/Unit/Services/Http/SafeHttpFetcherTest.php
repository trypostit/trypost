<?php

declare(strict_types=1);

use App\Services\Http\FetchedBody;
use App\Services\Http\HostResolver;
use App\Services\Http\SafeHttpFetcher;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

test('tryGetBody returns a body within the requested byte limit', function () {
    Http::fake([
        'https://93.184.216.34/page' => Http::response('1234', 200),
    ]);

    expect(app(SafeHttpFetcher::class)->tryGetBody('https://93.184.216.34/page', 4))
        ->toBe('1234');
});

test('tryGetBody cleans up when the transport closes its sink before failing', function () {
    Http::fake(function ($request, array $options) {
        $sink = $options['sink'];
        $body = Utils::streamFor(
            is_string($sink) ? Utils::tryFopen($sink, 'w+') : $sink,
        );
        $body->close();

        throw new RuntimeException('Transport failed after closing the response body.');
    });

    expect(app(SafeHttpFetcher::class)->tryGetBody('https://93.184.216.34/page', 4))->toBeNull();
});

test('tryGetBody rejects a body larger than the requested byte limit', function () {
    Http::fake([
        'https://93.184.216.34/page' => Http::response('12345', 200),
    ]);

    expect(app(SafeHttpFetcher::class)->tryGetBody('https://93.184.216.34/page', 4))
        ->toBeNull();
});

test('tryGetBody aborts an oversized transfer and closes its temporary stream', function (int $total, int $downloaded) {
    $stream = null;
    $completed = false;

    Http::fake(function ($request, array $options) use ($total, $downloaded, &$stream, &$completed) {
        $stream = $options['sink'];
        expect(is_file($stream))->toBeTrue();

        $options['progress']($total, $downloaded);
        $completed = true;

        return Http::response('should never finish');
    });

    expect(app(SafeHttpFetcher::class)->tryGetBody('https://93.184.216.34/page', 4))->toBeNull()
        ->and($completed)->toBeFalse()
        ->and(file_exists($stream))->toBeFalse();
})->with([
    'declared length' => [5, 0],
    'unknown length' => [0, 5],
]);

test('guardAgainstSsrf explains a private network block in a readable message', function () {
    $message = __('http.errors.private_network');

    expect($message)->not->toBe('http.errors.private_network');

    expect(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf('http://127.0.0.1/x'))
        ->toThrow(RuntimeException::class, $message);
});

test('guardAgainstSsrf localizes its message to the current locale', function () {
    app()->setLocale('pt-BR');

    expect(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf('ftp://93.184.216.34/x'))
        ->toThrow(RuntimeException::class, 'Apenas URLs http e https são suportadas.');
});

test('tryFetch returns the body and the final url after a guarded redirect', function () {
    Http::fake([
        'https://93.184.216.34/start' => fn () => Http::response('', 301, ['Location' => 'https://1.1.1.1/final']),
        'https://1.1.1.1/final' => fn () => Http::response('final body', 200),
    ]);

    $fetched = app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/start', 1024);

    expect($fetched)->toBeInstanceOf(FetchedBody::class)
        ->and($fetched->body)->toBe('final body')
        ->and($fetched->finalUrl)->toBe('https://1.1.1.1/final')
        ->and(app(SafeHttpFetcher::class)->tryGetBody('https://93.184.216.34/start', 1024))->toBe('final body');
});

test('tryFetch returns the requested url when there was no redirect', function () {
    Http::fake([
        'https://93.184.216.34/page' => Http::response('body', 200),
    ]);

    expect(app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/page', 1024)?->finalUrl)
        ->toBe('https://93.184.216.34/page');
});

test('tryFetch refuses a redirect to a private host', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/internal' => Http::response('internal secret', 200),
    ]);

    expect(app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/start', 1024))->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('tryFetch ignores a redirect history header injected by the server', function () {
    Http::fake([
        'https://93.184.216.34/page' => Http::response('body', 200, ['X-Guzzle-Redirect-History' => 'http://127.0.0.1/admin']),
    ]);

    expect(app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/page', 1024)?->finalUrl)
        ->toBe('https://93.184.216.34/page');
});

test('tryFetch reports the real redirect target when the final response injects a history header', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 301, ['Location' => 'https://1.1.1.1/final']),
        'https://1.1.1.1/final' => Http::response('final body', 200, ['X-Guzzle-Redirect-History' => 'http://127.0.0.1/admin']),
    ]);

    expect(app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/start', 1024)?->finalUrl)
        ->toBe('https://1.1.1.1/final');
});

test('tryFetch decodes a gzip body', function () {
    Http::fake([
        'https://93.184.216.34/page' => Http::response(gzencode('hello feed'), 200, ['Content-Encoding' => 'gzip']),
    ]);

    expect(app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/page', 1024)?->body)->toBe('hello feed');
});

test('tryFetch caps the decompressed size of a gzip body', function () {
    $bomb = gzencode(str_repeat('a', 1024 * 1024), 9);

    Http::fake([
        'https://93.184.216.34/page' => Http::response($bomb, 200, ['Content-Encoding' => 'gzip']),
    ]);

    expect(strlen($bomb))->toBeLessThan(8 * 1024)
        ->and(app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/page', 8 * 1024))->toBeNull();
});

test('tryFetch refuses an encoding it cannot decode', function () {
    Http::fake([
        'https://93.184.216.34/page' => Http::response('opaque', 200, ['Content-Encoding' => 'br']),
    ]);

    expect(app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/page', 1024))->toBeNull();
});

test('tryFetch only advertises gzip', function () {
    Http::fake(['https://93.184.216.34/page' => Http::response('body')]);

    app(SafeHttpFetcher::class)->tryFetch('https://93.184.216.34/page', 1024);

    Http::assertSent(fn ($request) => $request->header('Accept-Encoding') === ['gzip']);
});

test('limitTransfer asks for an identity body, never decodes it, and aborts past the cap', function () {
    $request = app(SafeHttpFetcher::class)->limitTransfer(Http::withUserAgent('test'), 100);
    $options = $request->getOptions();

    expect($options['decode_content'])->toBeFalse()
        ->and($options['headers']['Accept-Encoding'])->toBe('identity')
        ->and(fn () => $options['progress'](0, 100))->not->toThrow(RuntimeException::class)
        ->and(fn () => $options['progress'](0, 101))->toThrow(RuntimeException::class)
        ->and(fn () => $options['progress'](101, 0))->toThrow(RuntimeException::class);
});

test('limitTransfer aborts once the deadline has passed', function () {
    $request = app(SafeHttpFetcher::class)->limitTransfer(Http::withUserAgent('test'), 100, now()->subSecond());

    expect(fn () => $request->getOptions()['progress'](0, 1))->toThrow(RuntimeException::class);
});

/**
 * @param  array<string, list<list<string>>>  $answers  host => successive DNS answers
 */
function fakeDns(array $answers): void
{
    $calls = [];

    test()->mock(HostResolver::class)
        ->shouldReceive('addresses')
        ->andReturnUsing(function (string $host) use ($answers, &$calls): array {
            $index = $calls[$host] = ($calls[$host] ?? -1) + 1;
            $replies = $answers[$host] ?? [[]];

            return $replies[min($index, count($replies) - 1)];
        });
}

test('a host is refused when any AAAA record is not global', function (string $ipv6) {
    fakeDns(['dual.example.test' => [['93.184.216.34', $ipv6]]]);
    Http::fake();

    expect(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf('https://dual.example.test/x'))
        ->toThrow(RuntimeException::class, __('http.errors.private_network'))
        ->and(app(SafeHttpFetcher::class)->tryGet('https://dual.example.test/x'))->toBeNull();

    Http::assertNothingSent();
})->with(['::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1']);

test('non-global ranges are refused, CGNAT included', function (string $address) {
    fakeDns(['cloud.example.test' => [[$address]]]);

    expect(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf('https://cloud.example.test/x'))
        ->toThrow(RuntimeException::class, __('http.errors.private_network'))
        ->and(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf("http://{$address}/x"))
        ->toThrow(RuntimeException::class);
})->with(['100.100.100.200', '100.64.0.1', '198.18.0.1', '192.0.0.8', '169.254.169.254']);

test('bracketed IPv6 loopback literals are refused as private', function (string $url) {
    expect(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf($url))
        ->toThrow(RuntimeException::class, __('http.errors.private_network'));
})->with(['http://[::1]/x', 'http://[::ffff:127.0.0.1]/x', 'http://[fd00::1]/x']);

test('every request is pinned to the vetted addresses, redirect hops included', function () {
    fakeDns([
        'cdn.example.test' => [['93.184.216.34', '2606:2800:220:1::1']],
        'mirror.example.test' => [['1.1.1.1']],
    ]);
    $pins = [];
    Http::fake(function ($request, array $options) use (&$pins) {
        $pins[$request->url()] = data_get($options, 'curl.'.CURLOPT_RESOLVE);

        return str_contains($request->url(), 'cdn.example.test')
            ? Http::response('', 302, ['Location' => 'https://mirror.example.test/file'])
            : Http::response('body');
    });

    $fetcher = app(SafeHttpFetcher::class);
    $fetcher->guardedRequest('https://cdn.example.test/file')->get('https://cdn.example.test/file');

    expect($pins)->toBe([
        'https://cdn.example.test/file' => ['cdn.example.test:443:93.184.216.34,[2606:2800:220:1::1]'],
        'https://mirror.example.test/file' => ['mirror.example.test:443:1.1.1.1'],
    ]);

    $pins = [];
    $fetcher->get('https://cdn.example.test/file');

    expect($pins)->toHaveKey('https://cdn.example.test/file', ['cdn.example.test:443:93.184.216.34,[2606:2800:220:1::1]']);
});

test('a host that rebinds to a private address after the first check is still reached at the vetted address', function () {
    fakeDns(['rebind.example.test' => [['93.184.216.34'], ['127.0.0.1']]]);
    $pins = [];
    Http::fake(function ($request, array $options) use (&$pins) {
        $pins[] = data_get($options, 'curl.'.CURLOPT_RESOLVE);

        return Http::response('ok');
    });

    expect(app(SafeHttpFetcher::class)->tryGet('https://rebind.example.test/x')?->body())->toBe('ok')
        ->and($pins)->toBe([['rebind.example.test:443:93.184.216.34']]);
});

test('numeric and shorthand IPv4 hosts are refused', function (string $url) {
    Http::fake();

    expect(fn () => app(SafeHttpFetcher::class)->guardAgainstSsrf($url))->toThrow(RuntimeException::class)
        ->and(app(SafeHttpFetcher::class)->tryGet($url))->toBeNull();

    Http::assertNothingSent();
})->with([
    'decimal' => 'http://2130706433/x',
    'octal' => 'http://0177.0.0.1/x',
    'hex' => 'http://0x7f.0.0.1/x',
    'shorthand' => 'http://127.1/x',
    'unspecified' => 'http://0.0.0.0/x',
    'decimal metadata' => 'http://2852039166/latest/meta-data',
]);

test('a host is resolved once per fetch, redirect hops to it included', function () {
    $resolver = test()->mock(HostResolver::class);
    $resolver->shouldReceive('addresses')->with('cdn.example.test')->once()->andReturn(['93.184.216.34']);
    Http::fake([
        'https://cdn.example.test/start' => Http::response('', 302, ['Location' => 'https://cdn.example.test/final']),
        'https://cdn.example.test/final' => Http::response('body'),
    ]);

    expect(app(SafeHttpFetcher::class)->get('https://cdn.example.test/start')->body())->toBe('body');
});

test('bodyPrefix reads only the requested bytes of an endless body', function () {
    $endless = new PumpStream(fn (): string => str_repeat('x', 8192));
    $response = new Response(new Psr7Response(200, [], $endless));

    expect(app(SafeHttpFetcher::class)->bodyPrefix($response, 2000))->toBe(str_repeat('x', 2000))
        ->and(app(SafeHttpFetcher::class)->bodyPrefix(new Response(new Psr7Response(200, [], 'short')), 2000))->toBe('short');
});
