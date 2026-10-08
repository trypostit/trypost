<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
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

test('an unknown reason shows the message Google sent', function () {
    $e = new Exception('original error message', 400, null, [['reason' => 'someUnknownReason', 'message' => 'original error message']]);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('original error message')
        ->and($exception->rawResponse)->toBe('original error message');
});

test('a Google 5xx that reaches the mapper fails as an unconfirmed upload', function (int $status, array $errors) {
    $e = new Exception('<!DOCTYPE html><html lang=en><title>Error 502 (Server Error)!!1</title></html>', $status, null, $errors);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect(YouTubePublishException::isServerError($e))->toBeTrue()
        ->and($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe(__('posts.errors.youtube.upload_unconfirmed'));
})->with([
    '502 html page' => [502, []],
    '503 backendError' => [503, [['reason' => 'backendError', 'message' => 'Backend Error']]],
    '500 internalError' => [500, [['reason' => 'internalError', 'message' => 'Internal error']]],
    '400 backendError' => [400, [['reason' => 'backendError', 'message' => 'Backend Error']]],
    'internalError without a status' => [0, [['reason' => 'internalError', 'message' => 'Internal error']]],
]);

test('a failure without a documented reason never reaches the user message', function () {
    $e = new Exception('<!DOCTYPE html><html lang=en><p><b>400.</b> That’s an error.</html>', 400);

    $exception = YouTubePublishException::fromGoogleException($e);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe(__('posts.errors.unrecognized_error', ['platform' => Platform::YouTube->label()]))
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

test('fromApiResponse with an unknown reason shows the message Google sent', function () {
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

test('only a reason YouTube documents as caused by the user is marked as a network rejection', function (string $reason, int $status, bool $marked) {
    $fakeResponse = Http::fake(['*' => Http::response([
        'error' => ['code' => $status, 'message' => 'Rejected', 'errors' => [['reason' => $reason, 'message' => 'Rejected']]],
    ], $status)])->post(config('trypost.platforms.youtube.data_api').'/videos');

    expect(YouTubePublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBe($marked);
})->with([
    'invalid title' => ['invalidTitle', 400, true],
    'channel upload limit' => ['uploadLimitExceeded', 400, true],
    'our project quota' => ['quotaExceeded', 403, false],
    'category we send' => ['invalidCategoryId', 400, false],
    'privacy value we send' => ['forbiddenPrivacySetting', 403, false],
    'undescribed forbidden' => ['forbidden', 403, false],
    'video body missing from our request' => ['mediaBodyRequired', 400, false],
]);

test('a Google exception is marked only for a reason caused by the user', function (string $reason, bool $marked) {
    $exception = new Exception('Rejected', 400, null, [['reason' => $reason, 'message' => 'Rejected']]);

    expect(YouTubePublishException::fromGoogleException($exception)->isNetworkRejection())->toBe($marked);
})->with([
    'invalid title' => ['invalidTitle', true],
    'our project quota' => ['quotaExceeded', false],
]);

test('an unknown reason without a Google message shows the generic message', function () {
    $e = new Exception('{"error":{}}', 400, null, [['reason' => 'someUnknownReason']]);

    expect(YouTubePublishException::fromGoogleException($e)->userMessage)
        ->toBe(__('posts.errors.unrecognized_error', ['platform' => Platform::YouTube->label()]));
});

test('fromApiResponse shows the generic message without a Google message or on a 5xx', function (int $status, array $body) {
    $fakeResponse = Http::fake(['*' => Http::response($body, $status)])
        ->post(config('trypost.platforms.youtube.data_api').'/videos');

    expect(YouTubePublishException::fromApiResponse($fakeResponse)->userMessage)
        ->toBe(__('posts.errors.unrecognized_error', ['platform' => Platform::YouTube->label()]));
})->with([
    'no message' => [400, ['error' => ['code' => 400, 'errors' => [['reason' => 'weirdUnknownReason']]]]],
    'a 5xx' => [503, ['error' => ['code' => 503, 'message' => 'Backend unavailable', 'errors' => [['reason' => 'weirdUnknownReason']]]]],
]);
