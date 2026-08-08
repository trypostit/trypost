<?php

declare(strict_types=1);

use App\Services\Social\Meta\GraphError;

test('rate-limit and transient codes are transient', function () {
    foreach ([1, 2, 4, 17] as $code) {
        expect(GraphError::isTransient([
            'error' => ['message' => 'temporary problem', 'type' => 'OAuthException', 'code' => $code],
        ]))->toBeTrue();
    }
});

test('code 190 and other confirmed rejections are not transient', function () {
    expect(GraphError::isTransient([
        'error' => ['message' => 'Access token has expired', 'type' => 'OAuthException', 'code' => 190],
    ]))->toBeFalse();

    expect(GraphError::isTransient([
        'error' => ['message' => 'The requested resource does not exist', 'type' => 'OAuthException', 'code' => 100],
    ]))->toBeFalse();
});

test('a body with no error, or a null body, is not transient', function () {
    expect(GraphError::isTransient(null))->toBeFalse();
    expect(GraphError::isTransient([]))->toBeFalse();
});
