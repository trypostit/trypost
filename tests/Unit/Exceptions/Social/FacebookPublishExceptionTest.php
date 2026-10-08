<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
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

test('only a Graph code caused by the Page is marked as a network rejection', function (int $code, bool $marked) {
    $fakeResponse = Http::fake(['*' => Http::response([
        'error' => ['code' => $code, 'message' => 'Rejected'],
    ], 400)])->post(config('trypost.platforms.facebook.graph_api').'/123/feed');

    expect(FacebookPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBe($marked);
})->with([
    'unsupported video format' => [1363024, true],
    'video above the maximum size' => [1363023, true],
    'video below the minimum size' => [1363022, true],
    'file is not a valid video' => [1363032, true],
    'video too short' => [1363025, true],
    'video too long' => [1363026, true],
    'duplicate post' => [506, true],
    'user request limit' => [17, true],
    'Page BUC limit' => [80001, true],
    'app request limit' => [4, false],
    'user or app Page limit' => [32, false],
    'application limit' => [341, false],
    'custom limit' => [613, false],
    'no video file in our request' => [1363020, false],
    'upload problem to retry and report' => [6000, false],
    'no permission to upload, fixed with a valid token' => [1363042, false],
    'undocumented reel encoding code' => [1363047, false],
    'undocumented caption code' => [1390008, false],
    'undocumented rate limit' => [1349125, false],
]);

test('no rupload failure is marked as a network rejection', function (string $type) {
    $fakeResponse = Http::fake(['*' => Http::response(['debug_info' => ['type' => $type, 'message' => 'Failed']], 400)])
        ->post('https://'.config('trypost.platforms.facebook.rupload_host').'/video-upload/v25.0/1');

    expect(FacebookPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBeFalse();
})->with([
    'processing failed' => ['ProcessingFailedError'],
    'our partial request' => ['PartialRequestError'],
    'invalid upload offset' => ['OffsetInvalidError'],
]);

test('a rupload failure without a usable type has no platform error code', function (mixed $type) {
    $fakeResponse = Http::fake(['*' => Http::response(['debug_info' => ['type' => $type, 'message' => 'Failed']], 400)])
        ->post('https://'.config('trypost.platforms.facebook.rupload_host').'/video-upload/v25.0/1');

    $exception = FacebookPublishException::fromApiResponse($fakeResponse);

    expect($exception->platformErrorCode)->toBeNull()
        ->and($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe(__('posts.errors.unrecognized_error', ['platform' => Platform::Facebook->label()]));
})->with([
    'empty type' => [''],
    'array type' => [['ProcessingFailedError']],
    'integer type' => [42],
]);
