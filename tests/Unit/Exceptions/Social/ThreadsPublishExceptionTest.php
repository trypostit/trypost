<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\ThreadsPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('code 190 throws TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Invalid OAuth access token.',
            'type' => 'OAuthException',
            'code' => 190,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    ThreadsPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('error code 190 throws TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Access token expired.',
            'type' => 'ThreadsException',
            'code' => 190,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    ThreadsPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('HTTP 429 maps to RateLimit category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Application request limit reached.',
            'type' => 'ThreadsException',
            'code' => 4,
        ],
    ], 429);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception)
        ->toBeInstanceOf(ThreadsPublishException::class)
        ->and($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('Rate limit exceeded. Please try again later.');
});

test('body containing "text can\'t be blank" maps to ContentPolicy category', function () {
    $response = Http::response([
        'error' => [
            'message' => "text can't be blank",
            'type' => 'ThreadsException',
            'code' => 100,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Post text is required.');
});

test('"text can\'t be blank" check is case insensitive', function () {
    $response = Http::response([
        'error' => [
            'message' => "Text Can't Be Blank",
            'type' => 'ThreadsException',
            'code' => 100,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy);
});

test('unknown error maps to Unknown category with error message', function () {
    $response = Http::response([
        'error' => [
            'message' => 'An unexpected error occurred.',
            'type' => 'ThreadsException',
            'code' => 9999,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('An unexpected error occurred.');
});

test('code 24 subcode 4279009 is recognized as a missing media container', function () {
    $response = Http::response([
        'error' => [
            'message' => 'The requested resource does not exist',
            'type' => 'OAuthException',
            'code' => 24,
            'error_subcode' => 4279009,
            'error_user_title' => 'Media Not Found',
            'error_user_msg' => 'The media with id 17979429000118151 cannot be found.',
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception->isMissingMediaContainer())->toBeTrue()
        ->and($exception->platformErrorCode)->toBe('24')
        ->and($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('Threads could not find the processed media. Please try again.')
        ->and($exception->rawResponse)->toContain('4279009');
});

test('code 24 without the missing media subcode is not retryable', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Another media error',
            'type' => 'OAuthException',
            'code' => 24,
            'error_subcode' => 1234567,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception->isMissingMediaContainer())->toBeFalse();
});

test('HTTP 500 maps to ServerError category', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Internal server error.',
            'type' => 'ThreadsException',
            'code' => 1,
        ],
    ], 500);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('Threads server error. Please try again.');
});

test('platform returns threads', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Some error.',
            'type' => 'ThreadsException',
            'code' => 9999,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    $exception = ThreadsPublishException::fromApiResponse($fakeResponse);

    expect($exception->platform())->toBe('threads');
});
