<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use Illuminate\Http\Client\Response;

final class ThreadsMediaContainerNotFoundException extends ThreadsPublishException
{
    private const int ERROR_CODE = 24;

    private const int ERROR_SUBCODE = 4279009;

    public static function matches(Response $response): bool
    {
        return $response->status() === 400
            && $response->json('error.code') === self::ERROR_CODE
            && $response->json('error.error_subcode') === self::ERROR_SUBCODE;
    }

    public static function fromApiResponse(mixed $response, ?string $containerId = null): static
    {
        /** @var Response $response */
        $payload = $response->json();
        $errorUserMsg = trim((string) data_get($payload, 'error.error_user_msg', ''));
        $errorMessage = trim((string) data_get($payload, 'error.message', ''));
        $userMessage = 'Threads could not find the processed media. Please try again.';
        $detail = collect([
            is_string($containerId) && $containerId !== '' ? "container_id={$containerId}" : null,
            'code='.self::ERROR_CODE,
            'error_subcode='.self::ERROR_SUBCODE,
            $errorUserMsg !== '' ? "error_user_msg={$errorUserMsg}" : ($errorMessage !== '' ? "message={$errorMessage}" : null),
        ])->filter()->implode(', ');

        return new self(
            userMessage: $userMessage,
            category: ErrorCategory::ServerError,
            platformErrorCode: (string) self::ERROR_CODE,
            rawResponse: $response->body(),
            message: $detail !== '' ? "{$userMessage} ({$detail})" : $userMessage,
        );
    }
}
