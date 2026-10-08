<?php

declare(strict_types=1);

use App\Dto\BlueskySession;
use Illuminate\Support\Facades\Http;

test('bluesky session maps the authentication response without coercing values', function (?bool $emailConfirmed) {
    $response = Http::fake(['*' => Http::response([
        'did' => 'did:plc:account',
        'handle' => 'account.bsky.social',
        'accessJwt' => 'access-token',
        'refreshJwt' => 'refresh-token',
        'emailConfirmed' => $emailConfirmed,
    ])])->post('https://bsky.social/xrpc/com.atproto.server.createSession');

    $session = BlueskySession::fromResponse($response);

    expect($session)->toBeInstanceOf(BlueskySession::class)
        ->and($session->did)->toBe('did:plc:account')
        ->and($session->handle)->toBe('account.bsky.social')
        ->and($session->accessToken)->toBe('access-token')
        ->and($session->refreshToken)->toBe('refresh-token')
        ->and($session->emailConfirmed)->toBe($emailConfirmed)
        ->and($session->hasTokens())->toBeTrue();

    expect($session->__debugInfo())->toBe([
        'did' => 'did:plc:account',
        'handle' => 'account.bsky.social',
        'emailConfirmed' => $emailConfirmed,
    ]);
})->with([true, false, null]);

test('bluesky session accepts a session lookup without tokens or email confirmation', function () {
    $response = Http::fake(['*' => Http::response([
        'did' => 'did:plc:account',
        'handle' => 'account.bsky.social',
    ])])->get('https://bsky.social/xrpc/com.atproto.server.getSession');

    $session = BlueskySession::fromResponse($response);

    expect($session->accessToken)->toBeNull()
        ->and($session->refreshToken)->toBeNull()
        ->and($session->emailConfirmed)->toBeNull()
        ->and($session->hasTokens())->toBeFalse();
});

test('bluesky session rejects malformed fields instead of casting them', function (string $field, mixed $value) {
    $body = [
        'did' => 'did:plc:account',
        'handle' => 'account.bsky.social',
        'accessJwt' => 'access-token',
        'refreshJwt' => 'refresh-token',
        'emailConfirmed' => true,
        $field => $value,
    ];

    $response = Http::fake(['*' => Http::response($body)])
        ->post('https://bsky.social/xrpc/com.atproto.server.createSession');

    expect(BlueskySession::fromResponse($response))->toBeNull();
})->with([
    'string true' => ['emailConfirmed', 'true'],
    'string false' => ['emailConfirmed', 'false'],
    'blank boolean' => ['emailConfirmed', ''],
    'whitespace boolean' => ['emailConfirmed', ' '],
    'numeric boolean' => ['emailConfirmed', 1],
    'string numeric boolean' => ['emailConfirmed', '1'],
    'array boolean' => ['emailConfirmed', []],
    'null did' => ['did', null],
    'blank did' => ['did', ' '],
    'array did' => ['did', []],
    'numeric handle' => ['handle', 123],
    'blank handle' => ['handle', ''],
    'null access token' => ['accessJwt', null],
    'array access token' => ['accessJwt', []],
    'blank access token' => ['accessJwt', ' '],
    'boolean refresh token' => ['refreshJwt', true],
    'blank refresh token' => ['refreshJwt', ''],
]);

test('bluesky session rejects incomplete or unsuccessful responses', function (array|string $body, int $status) {
    $response = Http::fake(['*' => Http::response($body, $status)])
        ->get('https://bsky.social/xrpc/com.atproto.server.getSession');

    expect(BlueskySession::fromResponse($response))->toBeNull();
})->with([
    'missing identity' => [[], 200],
    'missing handle' => [['did' => 'did:plc:account'], 200],
    'missing did' => [['handle' => 'account.bsky.social'], 200],
    'invalid json' => ['Bad Gateway', 200],
    'scalar json' => ['true', 200],
    'unsuccessful response' => [['did' => 'did:plc:account', 'handle' => 'account.bsky.social', 'emailConfirmed' => true], 401],
]);

test('bluesky session does not consider a partial token pair authenticated', function () {
    $response = Http::fake(['*' => Http::response([
        'did' => 'did:plc:account',
        'handle' => 'account.bsky.social',
        'accessJwt' => 'access-token',
    ])])->post('https://bsky.social/xrpc/com.atproto.server.createSession');

    expect(BlueskySession::fromResponse($response)->hasTokens())->toBeFalse();
});
