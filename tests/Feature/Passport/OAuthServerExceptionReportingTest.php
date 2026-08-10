<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use League\OAuth2\Server\Exception\OAuthServerException;

test('client-error oauth exceptions are not reported', function () {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(OAuthServerException::accessDenied()))->toBeFalse()
        ->and($handler->shouldReport(OAuthServerException::invalidGrant()))->toBeFalse()
        ->and($handler->shouldReport(OAuthServerException::invalidRequest('grant_type')))->toBeFalse();
});

test('server-error oauth exceptions are still reported', function () {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(OAuthServerException::serverError('unexpected failure')))->toBeTrue();
});

test('unrelated exceptions are unaffected', function () {
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(new RuntimeException('boom')))->toBeTrue();
});
