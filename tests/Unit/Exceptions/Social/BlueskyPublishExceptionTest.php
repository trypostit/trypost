<?php

declare(strict_types=1);

use App\Exceptions\Social\BlueskyPublishException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('ExpiredToken error throws TokenExpiredException', function () {
    $response = Http::response(['error' => 'ExpiredToken', 'message' => 'Token has expired.'], 401);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    BlueskyPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('InvalidToken error throws TokenExpiredException', function () {
    $response = Http::response(['error' => 'InvalidToken', 'message' => 'Token is invalid.'], 401);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    BlueskyPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('BlobTooLarge in body maps to MediaFormat category', function () {
    $response = Http::response(['error' => 'BlobTooLarge', 'message' => 'Blob too large.'], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    $exception = BlueskyPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::MediaFormat)
        ->and($exception->userMessage)->toBe("Image exceeds Bluesky's 1MB limit.");
});

test('HTTP 429 maps to RateLimit category', function () {
    $response = Http::response(['error' => 'RateLimitExceeded', 'message' => 'Too many requests.'], 429);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    $exception = BlueskyPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('Rate limit exceeded. Please try again later.');
});

test('unknown error maps to Unknown category with error message', function () {
    $response = Http::response(['error' => 'SomeUnknownError', 'message' => 'Something went wrong.'], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    $exception = BlueskyPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('Something went wrong.');
});

test('InvalidRequest error maps to ContentPolicy category', function () {
    $response = Http::response(['error' => 'InvalidRequest', 'message' => 'Post data is invalid.'], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    $exception = BlueskyPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Invalid post data.');
});

test('HTTP 500 maps to ServerError category', function () {
    $response = Http::response(['error' => 'InternalServerError', 'message' => 'Server error.'], 500);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    $exception = BlueskyPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('Bluesky server error. Please try again.');
});

test('platform returns bluesky', function () {
    $response = Http::response(['error' => 'SomeError', 'message' => 'Error.'], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://bsky.social/xrpc/test');

    $exception = BlueskyPublishException::fromApiResponse($fakeResponse);

    expect($exception->platform())->toBe('bluesky');
});

test('an invalid request and a rate limit from Bluesky are not network rejections', function (string $error, int $status) {
    $fakeResponse = Http::fake(['*' => Http::response(['error' => $error, 'message' => 'Rejected'], $status)])
        ->post('https://bsky.social/xrpc/com.atproto.repo.createRecord');

    expect(BlueskyPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBeFalse();
})->with([
    'our invalid request' => ['InvalidRequest', 400],
    'an account or IP rate limit' => ['RateLimitExceeded', 429],
]);
