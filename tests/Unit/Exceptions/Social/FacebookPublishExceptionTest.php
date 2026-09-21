<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('code 1363031 maps to MediaFormat category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Unsupported file format.',
            'type' => 'FacebookApiException',
            'code' => 1363031,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception)
        ->toBeInstanceOf(FacebookPublishException::class)
        ->and($exception->userMessage)->toBe('Unsupported file format.')
        ->and($exception->category)->toBe(ErrorCategory::MediaFormat);
});

test('code 190 throws TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Invalid OAuth access token.',
            'type' => 'FacebookApiException',
            'code' => 190,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    FacebookPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('OAuthException with non-190 code does not throw TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'message' => 'There was a problem uploading your video file.',
            'type' => 'OAuthException',
            'code' => 6000,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception)->toBeInstanceOf(FacebookPublishException::class);
    expect($exception->category)->toBe(ErrorCategory::MediaFormat);
});

test('code 4 maps to RateLimit category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Too many API calls.',
            'type' => 'FacebookApiException',
            'code' => 4,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('Too many API calls. Please try again later.');
});

test('code 1363042 maps to Permission category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'No permission to upload video here.',
            'type' => 'FacebookApiException',
            'code' => 1363042,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('No permission to upload video here.');
});

test('code 506 maps to ContentPolicy category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Duplicate post.',
            'type' => 'FacebookApiException',
            'code' => 506,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Duplicate post detected. Please modify content.');
});

test('unknown code maps to Unknown category with error message', function () {
    $response = Http::response([
        'error' => [
            'message' => 'An unexpected error occurred.',
            'type' => 'FacebookApiException',
            'code' => 9999999,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('An unexpected error occurred.');
});

test('code 200 keeps the facebook-url subcode', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Permissions error',
            'type' => 'OAuthException',
            'code' => 200,
            'error_subcode' => 1609008,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception->platformErrorCode)->toBe('200')
        ->and($exception->platformErrorSubcode)->toBe('1609008')
        ->and($exception->userMessage)->toBe('Permissions error');
});

test('a link rejection is told apart from a failed post', function (array $error, bool $rejectsLink) {
    $fakeResponse = Http::fake(['*' => Http::response(['error' => $error], 400)])
        ->post('https://graph.facebook.com/test');

    expect(FacebookPublishException::fromApiResponse($fakeResponse)->rejectsLink())->toBe($rejectsLink);
})->with([
    'scrape failed' => [['message' => 'There was a problem scraping the URL.', 'code' => 1609005], true],
    'invalid url' => [['message' => 'The url you supplied is invalid', 'code' => 1500], true],
    'facebook.com link' => [['message' => 'Permissions error', 'code' => 200, 'error_subcode' => 1609008], true],
    'permissions error without the subcode' => [['message' => 'Permissions error', 'code' => 200], false],
    'duplicate post' => [['message' => 'Duplicate post', 'code' => 506], false],
]);

test('subcode 463 throws TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Session expired.',
            'type' => 'FacebookApiException',
            'code' => 102,
            'error_subcode' => 463,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.facebook.com/test');

    FacebookPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);
