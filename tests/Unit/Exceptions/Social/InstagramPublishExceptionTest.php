<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('known subcode 2207026 maps to correct message and MediaFormat category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Some API message',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 2207026,
            'is_transient' => false,
            'error_user_msg' => 'Video format not supported.',
            'fbtrace_id' => 'abc123',
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception)
        ->toBeInstanceOf(InstagramPublishException::class)
        ->and($exception->userMessage)->toBe('Unsupported video format. Please upload MP4 or MOV.')
        ->and($exception->category)->toBe(ErrorCategory::MediaFormat);
});

test('known subcode 2207042 maps to RateLimit category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Rate limit hit',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 2207042,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('Daily publishing limit reached. Please try again tomorrow.');
});

test('known subcode 2207050 maps to Permission category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Account restricted',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 2207050,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('Instagram account is restricted or inactive. Please check the Instagram app.');
});

test('known subcode 2207010 maps to ContentPolicy category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Caption too long',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 2207010,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Caption is too long (max 2,200 characters, 30 hashtags, 20 @mentions).');
});

test('code 190 throws TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Invalid OAuth access token',
            'type' => 'OAuthException',
            'code' => 190,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    InstagramPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('error code 190 throws TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Invalid access token',
            'type' => 'IGApiException',
            'code' => 190,
            'error_subcode' => 460,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    InstagramPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('unknown subcode with error_user_msg uses that message', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Generic API message',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 9999999,
            'error_user_msg' => 'A friendly user-facing message.',
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception->userMessage)->toBe('A friendly user-facing message.')
        ->and($exception->category)->toBe(ErrorCategory::Unknown);
});

test('unknown subcode without error_user_msg falls through to error message', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Some generic API error occurred.',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 9999999,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception->userMessage)->toBe('Some generic API error occurred.');
});

test('platformErrorCode is set to the subcode string', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Some error',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 2207026,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception->platformErrorCode)->toBe('2207026');
});

test('platform returns instagram', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Some error',
            'type' => 'IGApiException',
            'code' => 36000,
            'error_subcode' => 2207001,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.instagram.com/test');

    $exception = InstagramPublishException::fromApiResponse($fakeResponse);

    expect($exception->platform())->toBe('instagram');
});

test('only an Instagram error caused by the account is marked as a network rejection', function (int $code, ?int $subcode, bool $marked) {
    $fakeResponse = Http::fake(['*' => Http::response([
        'error' => array_filter(['code' => $code, 'error_subcode' => $subcode, 'message' => 'Rejected']),
    ], 400)])->post(config('trypost.platforms.instagram.graph_api').'/123/media');

    expect(InstagramPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBe($marked);
})->with([
    'unsupported video format' => [352, 2207026, true],
    'caption too long' => [36004, 2207010, true],
    'daily publishing limit' => [9, 2207042, true],
    'account restricted' => [25, 2207050, true],
    'user request limit' => [17, null, true],
    'Instagram BUC limit' => [80002, null, true],
    'the media type we send' => [100, 2207023, false],
    'product tags we never send' => [100, 2207037, false],
    'image format whose fix is a permission or token' => [36001, 2207005, false],
    'app request limit' => [4, null, false],
    'server error' => [-1, 2207001, false],
]);

test('a container status is marked only for a subcode caused by the account', function (int $status, bool $marked) {
    expect(InstagramPublishException::fromContainerStatus($status, null)->isNetworkRejection())->toBe($marked);
})->with([
    'unsupported video format' => [2207026, true],
    'the media type we send' => [2207023, false],
]);
