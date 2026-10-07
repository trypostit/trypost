<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\LinkedInPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('HTTP 401 throws TokenExpiredException', function () {
    $response = Http::response(['message' => 'Unauthorized'], 401);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.linkedin.com/test');

    LinkedInPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('HTTP 403 maps to Permission category', function () {
    $response = Http::response(['message' => 'Forbidden'], 403);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.linkedin.com/test');

    $exception = LinkedInPublishException::fromApiResponse($fakeResponse);

    expect($exception)
        ->toBeInstanceOf(LinkedInPublishException::class)
        ->and($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('Not authorized to post to this account.');
});

test('a 400 saying "Unable to obtain activity" is not a server error', function () {
    $response = Http::response(['message' => 'Unable to obtain activity for URN'], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.linkedin.com/test');

    $exception = LinkedInPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('Unable to obtain activity for URN');
});

test('an unmapped error shows the message LinkedIn sent', function () {
    $response = Http::response(['message' => 'Something went wrong.'], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.linkedin.com/test');

    $exception = LinkedInPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('Something went wrong.');
});

test('HTTP 422 maps to ContentPolicy category', function () {
    $response = Http::response(['message' => 'Validation error'], 422);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.linkedin.com/test');

    $exception = LinkedInPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Invalid post data. Please check your content.');
});

test('a 403 maps to Permission by its status, whatever the message', function () {
    $response = Http::response(['message' => 'Something else entirely'], 403);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.linkedin.com/test');

    $exception = LinkedInPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('Not authorized to post to this account.');
});

test('a LinkedIn 5xx maps to ServerError without the raw RestException dump', function () {
    $body = ['message' => 'RestException{_response=RestResponse[headers={content-length=17616, content-type=application/json, x-restli-error-response=true},cookies=[],status=500,entityLength=17616]}', 'status' => 500];

    $fakeResponse = Http::fake(['*' => Http::response($body, 500)])
        ->post(config('trypost.platforms.linkedin.api').'/rest/posts');

    $exception = LinkedInPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe(__('posts.errors.linkedin.server_error'))
        ->and($exception->userMessage)->not->toContain('RestException')
        ->and($exception->rawResponse)->toContain('RestException');
});

test('platform returns linkedin', function () {
    $response = Http::response(['message' => 'Some error'], 400);

    $fakeResponse = Http::fake(['*' => $response])->post('https://api.linkedin.com/test');

    $exception = LinkedInPublishException::fromApiResponse($fakeResponse);

    expect($exception->platform())->toBe('linkedin');
});

test('only a status LinkedIn documents as caused by the member is marked as a network rejection', function (int $status, bool $marked) {
    $fakeResponse = Http::fake(['*' => Http::response(['message' => 'Rejected'], $status)])
        ->post(config('trypost.platforms.linkedin.api').'/rest/posts');

    expect(LinkedInPublishException::fromApiResponse($fakeResponse)->isNetworkRejection())->toBe($marked);
})->with([
    'permission' => [403, true],
    'semantic errors in our fields' => [422, false],
    'member or application limit' => [429, false],
    'server error' => [500, false],
    'unknown' => [400, false],
]);

test('an exception we raise ourselves is never a network rejection', function () {
    $exception = new LinkedInPublishException(userMessage: 'LinkedIn organization ID not configured.', category: ErrorCategory::Permission);

    expect($exception->isNetworkRejection())->toBeFalse();
});

test('an unmapped error without a message shows the generic message', function (array $body) {
    $fakeResponse = Http::fake(['*' => Http::response($body, 400)])
        ->post(config('trypost.platforms.linkedin.api').'/rest/posts');

    expect(LinkedInPublishException::fromApiResponse($fakeResponse)->userMessage)
        ->toBe(__('posts.errors.unrecognized_error', ['platform' => 'LinkedIn']));
})->with([
    'no message' => [['status' => 400]],
    'an empty message' => [['message' => '  ', 'status' => 400]],
]);
