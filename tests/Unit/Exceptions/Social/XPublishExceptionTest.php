<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\XPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('HTTP 401 throws TokenExpiredException', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/not-authorized-for-resource',
        'title' => 'Unauthorized',
        'detail' => 'Could not authenticate you.',
    ], 401);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    XPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('type unsupported-authentication throws TokenExpiredException', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/unsupported-authentication',
        'title' => 'Unsupported Authentication',
        'detail' => 'Authentication method not supported.',
    ], 403);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    XPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('type usage-capped maps to RateLimit category', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/usage-capped',
        'title' => 'Usage Capped',
        'detail' => 'You have exceeded your monthly usage cap.',
    ], 403);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception)
        ->toBeInstanceOf(XPublishException::class)
        ->and($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('Usage limit exceeded. Please try again later.');
});

test('type client-forbidden maps to Permission category', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/client-forbidden',
        'title' => 'Client Forbidden',
        'detail' => 'This app is not enrolled.',
    ], 403);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('App not enrolled or lacks required access.');
});

test('HTTP 500 maps to ServerError category', function () {
    $response = Http::response([
        'title' => 'Internal Server Error',
        'detail' => 'Something went wrong on our end.',
    ], 500);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('X server error. Please try again later.');
});

test('HTTP 413 with empty body maps to MediaFormat category', function () {
    $response = Http::response('', 413);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::MediaFormat)
        ->and($exception->userMessage)->toBe('Media chunk rejected by X (payload too large).')
        ->and($exception->platformErrorCode)->toBe('413');
});

test('unknown type maps to Unknown category with detail as message', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/some-unknown-problem',
        'title' => 'Some Unknown Problem',
        'detail' => 'An unknown issue occurred.',
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('An unknown issue occurred.');
});

test('body containing "invalid URL" maps to ContentPolicy category', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/invalid-request',
        'title' => 'Invalid Request',
        'detail' => 'The post contains an invalid URL in the content.',
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Post contains an invalid URL.');
});

test('a duplicate post maps to ContentPolicy with a translated message', function () {
    $response = Http::response([
        'detail' => 'You are not allowed to create a Tweet with duplicate content.',
        'type' => 'about:blank',
        'title' => 'Forbidden',
        'status' => 403,
    ], 403);

    $fakeResponse = Http::fake(['*' => $response])->post(config('trypost.platforms.x.api').'/tweets');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe(__('posts.errors.x.duplicate_content'))
        ->and($exception->platformErrorCode)->toBe('duplicate-content');
});

test('body containing "video longer than 2 minutes" maps to MediaFormat category', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/invalid-request',
        'title' => 'Invalid Request',
        'detail' => 'The video longer than 2 minutes cannot be uploaded.',
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::MediaFormat)
        ->and($exception->userMessage)->toBe('Video exceeds the 2-minute limit.');
});

test('invalid media IDs maps to MediaFormat category', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/invalid-request',
        'title' => 'Invalid Request',
        'detail' => 'One or more parameters to your request was invalid.',
        'errors' => [['message' => 'Your media IDs are invalid.']],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::MediaFormat)
        ->and($exception->userMessage)->toBe('X rejected the attached media. Please re-upload and try again.');
});

test('JSON object body requirement maps to ServerError category', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/invalid-request',
        'title' => 'Invalid Request',
        'detail' => 'One or more parameters to your request was invalid.',
        'errors' => [['message' => 'Request body must be a JSON object.']],
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('X rejected the media upload request. Please try again.');
});

test('platform returns x', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/some-unknown-problem',
        'title' => 'Error',
        'detail' => 'Some detail.',
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->platform())->toBe('x');
});
