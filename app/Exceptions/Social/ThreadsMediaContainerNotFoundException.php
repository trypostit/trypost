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

    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        return new self(
            userMessage: 'Threads could not find the processed media. Please try again.',
            category: ErrorCategory::ServerError,
            platformErrorCode: (string) self::ERROR_CODE,
            rawResponse: $response->body(),
        );
    }
}
