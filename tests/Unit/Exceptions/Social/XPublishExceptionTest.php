<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
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

test('an unmapped type shows the detail X sent', function () {
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

test('only a problem type X documents as caused by the account is marked as a network rejection', function (int $status, string $type, bool $marked) {
    $fakeResponse = Http::fake(['*' => Http::response([
        'type' => "https://api.twitter.com/2/problems/{$type}",
        'title' => 'Rejected',
    ], $status)])->post(config('trypost.platforms.x.api').'/tweets');

    expect(XPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBe($marked);
})->with([
    'no access to protected content' => [403, 'not-authorized-for-resource', true],
    'our app is not enrolled' => [403, 'client-forbidden', false],
    'our malformed request' => [400, 'invalid-request', false],
    'an id we sent that does not exist' => [404, 'resource-not-found', false],
    'our app usage cap' => [429, 'usage-capped', false],
    'a user or app rate limit' => [429, 'rate-limit-exceeded', false],
]);

test('a payload too large and a bare 429 from X are not network rejections', function (int $status) {
    $fakeResponse = Http::fake(['*' => Http::response([], $status)])
        ->post(config('trypost.platforms.x.api').'/media/upload');

    expect(XPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBeFalse();
})->with([413, 429]);

test('an unmapped type without a detail shows the title X sent', function () {
    $fakeResponse = Http::fake(['*' => Http::response([
        'type' => 'about:blank',
        'title' => 'Forbidden',
        'detail' => '',
    ], 403)])->post(config('trypost.platforms.x.api').'/tweets');

    expect(XPublishException::fromApiResponse($fakeResponse)->userMessage)->toBe('Forbidden');
});

test('an unmapped error shows the generic message when X sent no explanation', function (int $status, array $body) {
    $fakeResponse = Http::fake(['*' => Http::response($body, $status)])
        ->post(config('trypost.platforms.x.api').'/tweets');

    $exception = XPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe(__('posts.errors.unrecognized_error', ['platform' => Platform::X->label()]));
})->with([
    'no message' => [400, ['type' => 'about:blank']],
    'an unlisted 5xx' => [501, ['type' => 'about:blank', 'title' => 'Not Implemented', 'detail' => 'Upstream failed.']],
]);
