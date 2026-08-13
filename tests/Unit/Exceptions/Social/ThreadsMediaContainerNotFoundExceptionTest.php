<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\ThreadsMediaContainerNotFoundException;
use Illuminate\Support\Facades\Http;

test('matches code 24 subcode 4279009', function () {
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

    expect(ThreadsMediaContainerNotFoundException::matches($fakeResponse))->toBeTrue();

    $exception = ThreadsMediaContainerNotFoundException::fromApiResponse($fakeResponse);

    expect($exception->platformErrorCode)->toBe('24')
        ->and($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('Threads could not find the processed media. Please try again.')
        ->and($exception->rawResponse)->toContain('4279009');
});

test('does not match code 24 without the missing media subcode', function () {
    $response = Http::response([
        'error' => [
            'message' => 'Another media error',
            'type' => 'OAuthException',
            'code' => 24,
            'error_subcode' => 1234567,
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    expect(ThreadsMediaContainerNotFoundException::matches($fakeResponse))->toBeFalse();
});

test('does not match the missing media payload outside an HTTP 400 response', function () {
    $response = Http::response([
        'error' => [
            'code' => 24,
            'error_subcode' => 4279009,
        ],
    ], 500);

    $fakeResponse = Http::fake(['*' => $response])->post('https://graph.threads.net/test');

    expect(ThreadsMediaContainerNotFoundException::matches($fakeResponse))->toBeFalse();
});
