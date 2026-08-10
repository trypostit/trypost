<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\TikTokPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('HTTP error rate_limit_exceeded maps to RateLimit category', function () {
    $response = Http::response([
        'error' => [
            'code' => 'rate_limit_exceeded',
            'message' => 'Rate limit hit.',
            'log_id' => 'abc123',
        ],
    ], 429);

    $fakeResponse = Http::fake(['*' => $response])->post(config('trypost.platforms.tiktok.api').'/test');

    $exception = TikTokPublishException::fromApiResponse($fakeResponse);

    expect($exception)
        ->toBeInstanceOf(TikTokPublishException::class)
        ->and($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('TikTok rate limit exceeded. Please try again later.')
        ->and($exception->platformErrorCode)->toBe('rate_limit_exceeded');
});

test('HTTP error access_token_invalid throws TokenExpiredException', function () {
    $response = Http::response([
        'error' => [
            'code' => 'access_token_invalid',
            'message' => 'The access token is invalid.',
            'log_id' => 'xyz789',
        ],
    ], 401);

    $fakeResponse = Http::fake(['*' => $response])->post(config('trypost.platforms.tiktok.api').'/test');

    TikTokPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('HTTP error scope_not_authorized (also a 401) maps to Permission category, not TokenExpiredException', function () {
    // TikTok reports a missing video.publish grant as a 401, the same status
    // it uses for a genuinely dead token — https://developers.tiktok.com/doc/content-posting-api-reference-direct-post.
    // A scope gap must not disconnect the account.
    $response = Http::response([
        'error' => [
            'code' => 'scope_not_authorized',
            'message' => "The access_token does not bear user's grant on video.publish scope",
            'log_id' => 'scope123',
        ],
    ], 401);

    $fakeResponse = Http::fake(['*' => $response])->post(config('trypost.platforms.tiktok.api').'/test');

    $exception = TikTokPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('Missing required permissions. Please reconnect with all scopes.');
});

test('HTTP error invalid_file_upload maps to MediaFormat category', function () {
    $response = Http::response([
        'error' => [
            'code' => 'invalid_file_upload',
            'message' => 'File does not meet specifications.',
            'log_id' => 'def456',
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post(config('trypost.platforms.tiktok.api').'/test');

    $exception = TikTokPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::MediaFormat)
        ->and($exception->userMessage)->toBe('File does not meet API specifications.');
});

test('fail reason spam_risk_too_many_posts maps to RateLimit category', function () {
    $exception = TikTokPublishException::fromFailReason('spam_risk_too_many_posts');

    expect($exception)
        ->toBeInstanceOf(TikTokPublishException::class)
        ->and($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('Daily posting limit reached. Try again tomorrow.')
        ->and($exception->platformErrorCode)->toBe('spam_risk_too_many_posts');
});

test('fail reason video_pull_failed maps to ServerError category', function () {
    $exception = TikTokPublishException::fromFailReason('video_pull_failed');

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('Failed to download video from URL.');
});

test('fail reason file_format_check_failed maps to MediaFormat category', function () {
    $exception = TikTokPublishException::fromFailReason('file_format_check_failed');

    expect($exception->category)->toBe(ErrorCategory::MediaFormat)
        ->and($exception->userMessage)->toBe('Unsupported media format.');
});

test('unknown error code falls through with Unknown category', function () {
    $response = Http::response([
        'error' => [
            'code' => 'some_unknown_error',
            'message' => 'Something went wrong.',
            'log_id' => 'ghi789',
        ],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post(config('trypost.platforms.tiktok.api').'/test');

    $exception = TikTokPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('Something went wrong.');
});

test('fromFailReason passes raw response through', function () {
    $rawResponse = '{"status":"failed","fail_reason":"video_pull_failed"}';

    $exception = TikTokPublishException::fromFailReason('video_pull_failed', $rawResponse);

    expect($exception->rawResponse)->toBe($rawResponse);
});

test('platform returns tiktok', function () {
    $exception = TikTokPublishException::fromFailReason('internal');

    expect($exception->platform())->toBe('tiktok');
});
