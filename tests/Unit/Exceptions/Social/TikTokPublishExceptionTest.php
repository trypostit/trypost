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

test('documented init refusals map to their category', function (string $code, int $status, ErrorCategory $category) {
    $fakeResponse = Http::fake(['*' => Http::response([
        'error' => ['code' => $code, 'message' => 'Refused.', 'log_id' => 'init123'],
    ], $status)])->post(config('trypost.platforms.tiktok.api').'/test');

    $exception = TikTokPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe($category)
        ->and($exception->platformErrorCode)->toBe($code)
        ->and($exception->isLimit())->toBe($category === ErrorCategory::RateLimit);
})->with([
    'daily post cap' => ['spam_risk_too_many_posts', 403, ErrorCategory::RateLimit],
    'pending share cap' => ['spam_risk_too_many_pending_share', 403, ErrorCategory::RateLimit],
    'active user quota' => ['reached_active_user_cap', 403, ErrorCategory::RateLimit],
    'rate limit' => ['rate_limit_exceeded', 429, ErrorCategory::RateLimit],
    'banned from posting' => ['spam_risk_user_banned_from_posting', 403, ErrorCategory::ContentPolicy],
    'invalid param' => ['invalid_param', 400, ErrorCategory::MediaFormat],
    'unaudited client' => ['unaudited_client_can_only_post_to_private_accounts', 403, ErrorCategory::Permission],
    'url ownership' => ['url_ownership_unverified', 403, ErrorCategory::Permission],
    'privacy mismatch' => ['privacy_level_option_mismatch', 403, ErrorCategory::Permission],
    'app version' => ['app_version_check_failed', 400, ErrorCategory::Permission],
    'unknown publish id' => ['invalid_publish_id', 400, ErrorCategory::Unknown],
    'publish id of another token' => ['token_not_authorized_for_specified_publish_id', 400, ErrorCategory::Unknown],
]);

test('only an error code TikTok documents as caused by the creator is marked as a network rejection', function (string $code, int $status, bool $marked) {
    $fakeResponse = Http::fake(['*' => Http::response([
        'error' => ['code' => $code, 'message' => 'Rejected'],
    ], $status)])->post(config('trypost.platforms.tiktok.api').'/post/publish/video/init/');

    expect(TikTokPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBe($marked);
})->with([
    'missing video.publish grant' => ['scope_not_authorized', 401, true],
    'grant revoked by the creator' => ['scope_permission_missed', 401, true],
    'per user token rate limit' => ['rate_limit_exceeded', 429, true],
    'file outside the specs' => ['invalid_file_upload', 400, true],
    'creator daily post cap' => ['spam_risk_too_many_posts', 403, true],
    'creator banned' => ['spam_risk_user_banned_from_posting', 403, true],
    'our domain not verified' => ['url_ownership_unverified', 403, false],
    'our app not audited' => ['unaudited_client_can_only_post_to_private_accounts', 403, false],
    'our invalid params' => ['invalid_param', 400, false],
    'MEDIA_UPLOAD app version, a mode we never send' => ['app_version_check_failed', 400, false],
    'our client active user cap' => ['reached_active_user_cap', 403, false],
    'privacy options we must honor' => ['privacy_level_option_mismatch', 403, false],
]);

test('only a fail reason caused by the creator is marked as a network rejection', function (string $failReason, bool $marked) {
    expect(TikTokPublishException::fromFailReason($failReason)->isNetworkRejection())->toBe($marked);
})->with([
    'unsupported media format' => ['file_format_check_failed', true],
    'video duration outside the limits' => ['duration_check_failed', true],
    'unsupported frame rate' => ['frame_rate_check_failed', true],
    'picture size outside the limits' => ['picture_size_check_failed', true],
    'spammy description' => ['spam_risk_text', true],
    'access removed by the creator' => ['auth_removed', true],
    'a developer cancel' => ['publish_cancelled', false],
    'a request flagged without a cause' => ['spam_risk', false],
]);
