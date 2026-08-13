<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class ThreadsPublishException extends SocialPublishException
{
    private const int MISSING_MEDIA_ERROR_CODE = 24;

    private const int MISSING_MEDIA_ERROR_SUBCODE = 4279009;

    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();
        $statusCode = $response->status();

        $errorCode = data_get($body, 'error.code');
        $errorSubcode = data_get($body, 'error.error_subcode');
        $errorMessage = data_get($body, 'error.message', 'An unknown Threads error occurred.');

        if ($errorCode === 190) {
            throw new TokenExpiredException(
                message: $errorMessage,
                platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
            );
        }

        if ($errorCode === self::MISSING_MEDIA_ERROR_CODE && $errorSubcode === self::MISSING_MEDIA_ERROR_SUBCODE) {
            return new static(
                userMessage: 'Threads could not find the processed media. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $errorCode,
                rawResponse: $rawResponse,
            );
        }

        if (stripos((string) $rawResponse, "text can't be blank") !== false) {
            return new static(
                userMessage: 'Post text is required.',
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
                rawResponse: $rawResponse,
            );
        }

        if ($statusCode === 429) {
            return new static(
                userMessage: 'Rate limit exceeded. Please try again later.',
                category: ErrorCategory::RateLimit,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            );
        }

        if ($statusCode >= 500) {
            return new static(
                userMessage: 'Threads server error. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: $errorMessage,
            category: ErrorCategory::Unknown,
            platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'threads';
    }

    public function isMissingMediaContainer(): bool
    {
        $body = json_decode($this->rawResponse ?? '', true);

        return data_get($body, 'error.code') === self::MISSING_MEDIA_ERROR_CODE
            && data_get($body, 'error.error_subcode') === self::MISSING_MEDIA_ERROR_SUBCODE;
    }
}
