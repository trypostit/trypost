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

test('unknown type maps to Unknown category with the generic message', function () {
    $response = Http::response([
        'type' => 'https://api.x.com/2/problems/some-unknown-problem',
        'title' => 'Some Unknown Problem',
        'detail' => 'An unknown issue occurred.',
    ], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.x.com/test');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe(__('posts.errors.unrecognized_error', ['platform' => 'X']))
        ->and($exception->rawResponse)->toContain('An unknown issue occurred.');
});

test('a duplicate post has no documented code and stays unclassified', function () {
    $response = Http::response([
        'detail' => 'You are not allowed to create a Tweet with duplicate content.',
        'type' => 'about:blank',
        'title' => 'Forbidden',
        'status' => 403,
    ], 403);

    $fakeResponse = Http::fake(['*' => $response])->post(config('trypost.platforms.x.api').'/tweets');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown);
});

test('the free-text detail never changes how an invalid-request is classified', function (array $body) {
    $fakeResponse = Http::fake(['*' => Http::response([
        'type' => 'https://api.x.com/2/problems/invalid-request',
        'title' => 'Invalid Request',
        ...$body,
    ], 400)])->post(config('trypost.platforms.x.api').'/tweets');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Invalid request. Check your post content.')
        ->and($exception->platformErrorCode)->toBe('invalid-request');
})->with([
    'invalid URL' => [['detail' => 'The post contains an invalid URL in the content.']],
    'video longer than 2 minutes' => [['detail' => 'The video longer than 2 minutes cannot be uploaded.']],
    'invalid media IDs' => [['detail' => 'One or more parameters to your request was invalid.', 'errors' => [['message' => 'Your media IDs are invalid.']]]],
    'JSON body requirement' => [['detail' => 'One or more parameters to your request was invalid.', 'errors' => [['message' => 'Request body must be a JSON object.']]]],
]);

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
