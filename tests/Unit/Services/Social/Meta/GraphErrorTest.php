<?php

declare(strict_types=1);

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Services\Social\Meta\GraphError;
use Illuminate\Support\Facades\Http;

test('rate-limit and transient codes are transient', function () {
    foreach ([1, 2, 4, 17] as $code) {
        expect(GraphError::isTransient([
            'error' => ['message' => 'temporary problem', 'type' => 'OAuthException', 'code' => $code],
        ]))->toBeTrue();
    }
});

test('Business Use Case (BUC) rate-limit codes are transient', function () {
    // Page/system-user tokens (our Facebook and InstagramFacebook accounts)
    // and Instagram Platform are throttled by a separate rate-limit system
    // from Platform Rate Limits (codes 4/17), with its own codes.
    // https://developers.facebook.com/docs/graph-api/overview/rate-limiting/
    expect(GraphError::isTransient([
        'error' => ['message' => 'There have been too many calls to this Page account.', 'code' => 80001],
    ]))->toBeTrue();

    expect(GraphError::isTransient([
        'error' => ['message' => 'Instagram Platform rate limit reached.', 'code' => 80002],
    ]))->toBeTrue();
});

test('code 190 and other confirmed rejections are not transient', function () {
    expect(GraphError::isTransient([
        'error' => ['message' => 'Access token has expired', 'type' => 'OAuthException', 'code' => 190],
    ]))->toBeFalse();

    expect(GraphError::isTransient([
        'error' => ['message' => 'The requested resource does not exist', 'type' => 'OAuthException', 'code' => 100],
    ]))->toBeFalse();
});

test('a body with no error is not transient, but a null (unparseable) body is', function () {
    // A body Meta didn't return as parseable JSON (WAF block page, truncated
    // response, gateway hiccup) carries no confirmed rejection — treat it as
    // transient rather than assuming the token is dead. A body that DID parse
    // but has no "error" key is a different case: it's a real response from
    // the server we called, just not shaped like Meta's usual error object —
    // intentionally NOT treated as transient, so an unrecognized 4xx shape
    // still results in a confirmed rejection rather than being silently
    // ignored (the exact bug this class was rewritten to close).
    expect(GraphError::isTransient(null))->toBeTrue();
    expect(GraphError::isTransient([]))->toBeFalse();
    expect(GraphError::isTransient(['data' => ['id' => '123']]))->toBeFalse();
});

test('isTransientFailure treats 5xx and 429 as transient regardless of body', function () {
    Http::fake(['example.com/*' => Http::response('upstream timeout', 503)]);
    expect(GraphError::isTransientFailure(Http::get('https://example.com/me')))->toBeTrue();

    Http::fake(['example.com/*' => Http::response(['error' => ['code' => 190]], 429)]);
    expect(GraphError::isTransientFailure(Http::get('https://example.com/me')))->toBeTrue();
});

test('isTransientFailure classifies a confirmed 4xx rejection as not transient', function () {
    Http::fake(['example.com/*' => Http::response(['error' => ['code' => 190]], 400)]);
    expect(GraphError::isTransientFailure(Http::get('https://example.com/me')))->toBeFalse();
});

test('classifyVerifyFailure returns PlatformUnavailableException for a transient failure', function () {
    Http::fake(['example.com/*' => Http::response('upstream timeout', 503)]);

    expect(GraphError::classifyVerifyFailure(Http::get('https://example.com/me'), 'Threads'))
        ->toBeInstanceOf(PlatformUnavailableException::class);
});

test('classifyVerifyFailure returns TokenExpiredException for a confirmed rejection', function () {
    Http::fake([
        'example.com/*' => Http::response([
            'error' => ['message' => 'The requested resource does not exist', 'code' => 100],
        ], 400),
    ]);

    $exception = GraphError::classifyVerifyFailure(Http::get('https://example.com/me'), 'Threads');

    expect($exception)->toBeInstanceOf(TokenExpiredException::class)
        ->and($exception->getMessage())->toBe('Threads access token is invalid or expired');
});
