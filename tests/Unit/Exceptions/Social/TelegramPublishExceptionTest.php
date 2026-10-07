<?php

declare(strict_types=1);

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\TelegramPublishException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function telegramErrorResponse(array $body, int $status): Response
{
    return Http::fake(['*' => Http::response($body, $status)])->post('https://api.telegram.org/botX/sendMessage');
}

test('HTTP 403 maps to Permission category', function () {
    $exception = TelegramPublishException::fromApiResponse(
        telegramErrorResponse(['ok' => false, 'description' => 'Forbidden'], 403),
    );

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->platformErrorCode)->toBe('403');
});

test('HTTP 401 maps to Permission category', function () {
    $exception = TelegramPublishException::fromApiResponse(
        telegramErrorResponse(['ok' => false, 'description' => 'Unauthorized'], 401),
    );

    expect($exception->category)->toBe(ErrorCategory::Permission)
        ->and($exception->platformErrorCode)->toBe('401');
});

test('HTTP 429 maps to RateLimit category', function () {
    $exception = TelegramPublishException::fromApiResponse(
        telegramErrorResponse(['ok' => false, 'description' => 'Too Many Requests'], 429),
    );

    expect($exception->category)->toBe(ErrorCategory::RateLimit);
});

test('HTTP 500 maps to ServerError category', function () {
    $exception = TelegramPublishException::fromApiResponse(
        telegramErrorResponse(['ok' => false, 'description' => 'Internal'], 500),
    );

    expect($exception->category)->toBe(ErrorCategory::ServerError);
});

test('other errors map to Unknown category with the api description', function () {
    $exception = TelegramPublishException::fromApiResponse(
        telegramErrorResponse(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
    );

    expect($exception->category)->toBe(ErrorCategory::Unknown)
        ->and($exception->userMessage)->toBe('Bad Request: chat not found');
});

test('platform returns telegram', function () {
    $exception = TelegramPublishException::fromApiResponse(
        telegramErrorResponse(['ok' => false, 'description' => 'Error'], 400),
    );

    expect($exception->platform())->toBe('telegram');
});

test('only a Telegram bot removed from the channel is marked as a network rejection', function (int $status, bool $marked) {
    expect(TelegramPublishException::fromApiResponse(telegramErrorResponse(['ok' => false, 'description' => 'Rejected'], $status))->isNetworkRejection())->toBe($marked);
})->with([
    'bot not an admin' => [403, true],
    'our bot flood limit' => [429, false],
]);
