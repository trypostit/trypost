<?php

declare(strict_types=1);

use App\Services\Social\Meta\GraphError;

test('code 190 indicates a genuinely invalid token', function () {
    expect(GraphError::indicatesInvalidToken([
        'error' => ['message' => 'Access token has expired', 'type' => 'OAuthException', 'code' => 190],
    ]))->toBeTrue();
});

test('rate-limit codes carried as OAuthException do NOT indicate an invalid token', function () {
    // Meta returns rate limits as HTTP 4xx with type OAuthException — they must
    // stay transient so a throttle never disconnects a still-valid token.
    expect(GraphError::indicatesInvalidToken([
        'error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 4],
    ]))->toBeFalse();

    expect(GraphError::indicatesInvalidToken([
        'error' => ['message' => 'User request limit reached', 'type' => 'OAuthException', 'code' => 17],
    ]))->toBeFalse();
});

test('transient codes do NOT indicate an invalid token', function () {
    expect(GraphError::indicatesInvalidToken([
        'error' => ['message' => 'Service temporarily unavailable', 'code' => 2],
    ]))->toBeFalse();
});

test('a body with no error, or a null body, does not indicate an invalid token', function () {
    expect(GraphError::indicatesInvalidToken(null))->toBeFalse();
    expect(GraphError::indicatesInvalidToken([]))->toBeFalse();
    expect(GraphError::indicatesInvalidToken(['data' => ['id' => '123']]))->toBeFalse();
});
