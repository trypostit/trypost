<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('unauthenticated status throws a token expired exception', function () {
    $response = Http::response(['error' => ['status' => 'UNAUTHENTICATED', 'message' => 'bad token']], 401);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    GoogleBusinessPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('permission denied maps to the permission category', function () {
    $response = Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'no access']], 403);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    $exception = GoogleBusinessPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Permission);
});

test('not found maps to the content policy category', function () {
    $response = Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'location gone']], 404);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    $exception = GoogleBusinessPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy);
});

test('invalid argument maps to the content policy category', function () {
    $response = Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid post format']], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    $exception = GoogleBusinessPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Invalid post format');
});

test('resource exhausted maps to the rate limit category', function () {
    $response = Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'slow down']], 429);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    $exception = GoogleBusinessPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::RateLimit);
});

test('500 status maps to the server error category', function () {
    $response = Http::response(['error' => ['status' => 'INTERNAL', 'message' => 'oops']], 500);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    $exception = GoogleBusinessPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError);
});

test('isConfirmedDeadToken is true for 401 status', function () {
    $response = Http::response([], 401);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    expect(GoogleBusinessPublishException::isConfirmedDeadToken($fakeResponse))->toBeTrue();
});

test('isConfirmedDeadToken is true for UNAUTHENTICATED status', function () {
    $response = Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    expect(GoogleBusinessPublishException::isConfirmedDeadToken($fakeResponse))->toBeTrue();
});

test('isConfirmedDeadToken is false for PERMISSION_DENIED status', function () {
    $response = Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403);
    $fakeResponse = Http::fake(['*' => $response])->post('https://mybusinessaccountmanagement.googleapis.com/test');

    expect(GoogleBusinessPublishException::isConfirmedDeadToken($fakeResponse))->toBeFalse();
});
