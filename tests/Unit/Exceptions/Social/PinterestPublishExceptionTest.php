<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\PinterestPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

test('HTTP 401 throws TokenExpiredException', function () {
    $response = Http::response(['message' => 'Access token expired.'], 401);
    $fakeResponse = Http::fake(['*' => $response])->post('https://api.pinterest.com/test');

    PinterestPublishException::fromApiResponse($fakeResponse);
})->throws(TokenExpiredException::class);

test('HTTP 429 maps to RateLimit category', function () {
    $response = Http::response(['message' => 'Too many requests.'], 429);
    $fakeResponse = Http::fake(['*' => $response])->post('https://api.pinterest.com/test');

    $exception = PinterestPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->userMessage)->toBe('Rate limit exceeded. Please try again later.');
});

test('HTTP 403 maps to Permission category', function () {
    $response = Http::response(['message' => 'Forbidden.'], 403);
    $fakeResponse = Http::fake(['*' => $response])->post('https://api.pinterest.com/test');

    $exception = PinterestPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('Not authorized to create pins on this board.');
});

test('HTTP 404 maps to ContentPolicy category', function () {
    $response = Http::response(['code' => 2, 'message' => 'Board not found.'], 404);
    $fakeResponse = Http::fake(['*' => $response])->post('https://api.pinterest.com/test');

    $exception = PinterestPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Pinterest could not find the selected board. It may have been deleted or you no longer have access to it.');
});

test('HTTP 400 with Pinterest error code 1 maps to ContentPolicy category', function () {
    $response = Http::response(['code' => 1, 'message' => "Sorry! This site doesn't allow you to save Pins."], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://api.pinterest.com/test');

    $exception = PinterestPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe("Pinterest rejected this pin. This usually means the content violates Pinterest's content policies (e.g. adult or sexual content).");
});

test('HTTP 400 with board in body maps to ContentPolicy category', function () {
    $response = Http::response(['message' => 'Invalid board_id provided.'], 400);
    $fakeResponse = Http::fake(['*' => $response])->post('https://api.pinterest.com/test');

    $exception = PinterestPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ContentPolicy)
        ->and($exception->userMessage)->toBe('Invalid board. Please select a valid board.');
});

test('processing status failed maps to MediaFormat category', function () {
    $exception = PinterestPublishException::fromProcessingStatus('failed');

    expect($exception->category)->toBe(ErrorCategory::MediaFormat)
        ->and($exception->userMessage)->toBe('Media processing failed. Please try a different file.');
});

test('processing status unknown maps to Unknown category', function () {
    $exception = PinterestPublishException::fromProcessingStatus('pending', 'raw response');

    expect($exception->category)->toBe(ErrorCategory::Unknown);
});

test('HTTP 500 maps to ServerError category', function () {
    $response = Http::response(['message' => 'Internal server error.'], 500);
    $fakeResponse = Http::fake(['*' => $response])->post('https://api.pinterest.com/test');

    $exception = PinterestPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->userMessage)->toBe('Pinterest server error. Please try again.');
});

test('platform returns pinterest', function () {
    $exception = PinterestPublishException::fromProcessingStatus('unknown');

    expect($exception->platform())->toBe('pinterest');
});
