<?php

declare(strict_types=1);

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\YouTubePublishException;
use App\Exceptions\TokenExpiredException;
use Google\Service\Exception;
use Illuminate\Support\Facades\Http;

// fromGoogleException tests

test('invalidTitle reason maps to ContentPolicy category', function () {
    $e = new Exception('message', 400, null, [['reason' => 'invalidTitle', 'message' => 'title invalid']]);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception)
        ->toBeInstanceOf(YouTubePublishException::class)
        ->and($exception->userMessage)->toBe('Video title is invalid or empty.')
        ->and($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->platformErrorCode)->toBe('invalidTitle');
});

test('uploadLimitExceeded reason maps to RateLimit category', function () {
    $e = new Exception('message', 403, null, [['reason' => 'uploadLimitExceeded', 'message' => 'limit exceeded']]);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception->userMessage)->toBe('Daily upload limit reached. Try again tomorrow.')
        ->and($exception->category)->toBe(ErrorCategory::RateLimit);
});

test('forbidden reason maps to Permission category', function () {
    $e = new Exception('message', 403, null, [['reason' => 'forbidden', 'message' => 'no permission']]);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception->userMessage)->toBe("You don't have permission to upload to this channel.")
        ->and($exception->category)->toBe(ErrorCategory::Permission);
});

test('HTTP 401 throws TokenExpiredException', function () {
    $e = new Exception('Unauthorized', 401, null, [['reason' => 'someReason', 'message' => 'token expired']]);

    YouTubePublishException::fromGoogleException($e);
})->throws(TokenExpiredException::class);

test('authError reason throws TokenExpiredException', function () {
    $e = new Exception('Auth error', 403, null, [['reason' => 'authError', 'message' => 'auth failed']]);

    YouTubePublishException::fromGoogleException($e);
})->throws(TokenExpiredException::class);

test('unauthorized reason throws TokenExpiredException', function () {
    $e = new Exception('Unauthorized', 403, null, [['reason' => 'unauthorized', 'message' => 'not authorized']]);

    YouTubePublishException::fromGoogleException($e);
})->throws(TokenExpiredException::class);

test('unknown reason falls through to Unknown category with original message', function () {
    $e = new Exception('original error message', 400, null, [['reason' => 'someUnknownReason', 'message' => 'original error message']]);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('original error message');
});

test('a Google 5xx is a transient outage, not a failed post', function (int $status, array $errors) {
    $e = new Exception('<!DOCTYPE html><html lang=en><title>Error 502 (Server Error)!!1</title></html>', $status, null, $errors);

    expect(fn () => YouTubePublishException::fromGoogleException($e))
        ->toThrow(fn (PlatformUnavailableException $exception) => expect($exception->httpStatus)->toBe($status));
})->with([
    '502 html page' => [502, []],
    '503 backendError' => [503, [['reason' => 'backendError', 'message' => 'Backend Error']]],
    '500 internalError' => [500, [['reason' => 'internalError', 'message' => 'Internal error']]],
]);

test('an unmapped failure with an html body never reaches the user message', function () {
    $e = new Exception('<!DOCTYPE html><html lang=en><p><b>400.</b> That’s an error.</html>', 400);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe(__('posts.errors.youtube.unexpected_response'))
        ->and($exception->userMessage)->not->toContain('<')
        ->and($exception->rawResponse)->toContain('<!DOCTYPE html>');
});

test('platform returns youtube', function () {
    $e = new Exception('message', 400, null, [['reason' => 'invalidTitle', 'message' => 'title invalid']]);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception->platform())->toBe('youtube');
});

// fromApiResponse tests

test('fromApiResponse with invalidTitle reason maps to ContentPolicy', function () {
    $response = Http::response([
        'error' => [
            'code' => 400,
            'message' => 'Invalid video title',
            'errors' => [
                ['reason' => 'invalidTitle', 'message' => 'title invalid'],
            ],
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://www.googleapis.com/youtube/v3/videos');

    $exception = YouTubePublishException::fromApiResponse($fakeResponse);

    expect($exception)
        ->toBeInstanceOf(YouTubePublishException::class)
        ->and($exception->userMessage)->toBe('Video title is invalid or empty.')
        ->and($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->platformErrorCode)->toBe('invalidTitle');
});

test('fromApiResponse with unknown reason uses fallback message and Unknown category', function () {
    $response = Http::response([
        'error' => [
            'code' => 400,
            'message' => 'Something went wrong on YouTube.',
            'errors' => [
                ['reason' => 'weirdUnknownReason', 'message' => 'Something went wrong on YouTube.'],
            ],
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://www.googleapis.com/youtube/v3/videos');

    $exception = YouTubePublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('Something went wrong on YouTube.');
});
