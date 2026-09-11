<?php

declare(strict_types=1);

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;

// Concrete implementation for testing the abstract class
class TestPlatformException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        return new static(
            userMessage: 'Test error',
            category: ErrorCategory::Unknown,
            platformErrorCode: null,
            rawResponse: json_encode($response),
        );
    }

    public function platform(): string
    {
        return 'test_platform';
    }
}

test('context returns correct data', function () {
    $exception = new TestPlatformException(
        userMessage: 'Your image is too large for Instagram.',
        category: ErrorCategory::MediaFormat,
        platformErrorCode: 'MEDIA_TOO_LARGE',
        rawResponse: '{"error": "image too large"}',
    );

    expect($exception->context())->toBe([
        'platform' => 'test_platform',
        'category' => 'media_format',
        'platform_error_code' => 'MEDIA_TOO_LARGE',
        'user_message' => 'Your image is too large for Instagram.',
        'raw_response' => '{"error": "image too large"}',
    ]);
});

test('context returns null for optional fields when not provided', function () {
    $exception = new TestPlatformException(
        userMessage: 'Something went wrong.',
        category: ErrorCategory::Unknown,
    );

    expect($exception->context())->toBe([
        'platform' => 'test_platform',
        'category' => 'unknown',
        'platform_error_code' => null,
        'user_message' => 'Something went wrong.',
        'raw_response' => null,
    ]);
});

test('platform unavailable context includes http status and checkpoint keys', function () {
    $exception = new PlatformUnavailableException(
        message: 'TikTok is still processing publish_id pub_stuck',
        httpStatus: 503,
        context: [
            'tiktok_publish_id' => 'pub_stuck',
            'tiktok_status' => 'PROCESSING_DOWNLOAD',
        ],
    );

    expect($exception->context())->toBe([
        'http_status' => 503,
        'tiktok_publish_id' => 'pub_stuck',
        'tiktok_status' => 'PROCESSING_DOWNLOAD',
    ]);
});

test('token expired context includes the platform error code', function () {
    $exception = new TokenExpiredException('Token expired', '190');

    expect($exception->context())->toBe([
        'platform_error_code' => '190',
    ]);
});

test('exception message matches user message', function () {
    $exception = new TestPlatformException(
        userMessage: 'Rate limit exceeded.',
        category: ErrorCategory::RateLimit,
    );

    expect($exception->getMessage())->toBe('Rate limit exceeded.');
});

test('exception message can carry Nightwatch detail without changing userMessage', function () {
    $exception = new TestPlatformException(
        userMessage: 'Rate limit exceeded.',
        category: ErrorCategory::RateLimit,
        message: 'Rate limit exceeded. (code=4)',
    );

    expect($exception->userMessage)->toBe('Rate limit exceeded.')
        ->and($exception->getMessage())->toBe('Rate limit exceeded. (code=4)');
});

test('fromApiResponse creates exception from response', function () {
    $exception = TestPlatformException::fromApiResponse(['error' => 'test']);

    expect($exception)
        ->toBeInstanceOf(SocialPublishException::class)
        ->and($exception->userMessage)->toBe('Test error')
        ->and($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->rawResponse)->toBe('{"error":"test"}');
});

test('error category enum has all expected cases', function () {
    expect(ErrorCategory::cases())->toHaveCount(10)
        ->and(ErrorCategory::MediaFormat->value)->toBe('media_format')
        ->and(ErrorCategory::RateLimit->value)->toBe('rate_limit')
        ->and(ErrorCategory::Permission->value)->toBe('permission')
        ->and(ErrorCategory::ContentPolicy->value)->toBe('content_policy')
        ->and(ErrorCategory::ServerError->value)->toBe('server_error')
        ->and(ErrorCategory::Unknown->value)->toBe('unknown')
        ->and(ErrorCategory::PlatformUnavailable->value)->toBe('platform_unavailable')
        ->and(ErrorCategory::Timeout->value)->toBe('timeout')
        ->and(ErrorCategory::TokenExpired->value)->toBe('token_expired')
        ->and(ErrorCategory::JobFailed->value)->toBe('job_failed');
});

test('error category marks only in-flight interruptions as resumable', function (ErrorCategory $category, bool $resumable) {
    expect($category->isResumable())->toBe($resumable);
})->with([
    'platform unavailable' => [ErrorCategory::PlatformUnavailable, true],
    'timeout' => [ErrorCategory::Timeout, true],
    'token expired' => [ErrorCategory::TokenExpired, true],
    'job failed' => [ErrorCategory::JobFailed, true],
    'media format' => [ErrorCategory::MediaFormat, false],
    'content policy' => [ErrorCategory::ContentPolicy, false],
    'server error' => [ErrorCategory::ServerError, false],
    'permission' => [ErrorCategory::Permission, false],
    'rate limit' => [ErrorCategory::RateLimit, false],
    'unknown' => [ErrorCategory::Unknown, false],
]);

test('error category tryFromContext reads a stored category', function (?array $context, ?ErrorCategory $expected) {
    expect(ErrorCategory::tryFromContext($context))->toBe($expected);
})->with([
    'resumable' => [['category' => 'token_expired'], ErrorCategory::TokenExpired],
    'unknown string' => [['category' => 'not-a-category'], null],
    'missing' => [[], null],
    'null context' => [null, null],
]);
