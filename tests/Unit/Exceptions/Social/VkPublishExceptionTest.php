<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\VkPublishException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Http;

function fakeVkErrorResponse(int $code, string $msg, int $status = 200)
{
    $response = Http::response(['error' => ['error_code' => $code, 'error_msg' => $msg]], $status);

    return Http::fake(['*' => $response])->post('https://vk.example/method/wall.post');
}

test('error 5 (authorization failed) throws TokenExpiredException', function () {
    VkPublishException::fromApiResponse(fakeVkErrorResponse(5, 'User authorization failed.'));
})->throws(TokenExpiredException::class);

test('error 6 (too many requests) maps to RateLimit category', function () {
    $exception = VkPublishException::fromApiResponse(fakeVkErrorResponse(6, 'Too many requests per second.'));

    expect($exception->category)->toBe(ErrorCategory::RateLimit)
        ->and($exception->platformErrorCode)->toBe('6');
});

test('error 214 (post access denied) maps to Permission category', function () {
    $exception = VkPublishException::fromApiResponse(fakeVkErrorResponse(214, 'Access to adding post denied.'));

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->userMessage)->toBe('Access to adding post denied.');
});

test('unknown error code maps to Unknown category with vk platform', function () {
    $exception = VkPublishException::fromApiResponse(fakeVkErrorResponse(1, 'Unknown error occurred.'));

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->platform())->toBe('vk');
});

test('transport 5xx without vk error object maps to ServerError', function () {
    $response = Http::response('Bad gateway', 502);
    $fakeResponse = Http::fake(['*' => $response])->post('https://vk.example/method/wall.post');

    $exception = VkPublishException::fromApiResponse($fakeResponse);

    expect($exception->category)->toBe(ErrorCategory::ServerError)
        ->and($exception->platformErrorCode)->toBe('502');
});
