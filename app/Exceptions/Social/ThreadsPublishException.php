<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class ThreadsPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();
        $statusCode = $response->status();

        $errorCode = data_get($body, 'error.code');
        $errorMessage = data_get($body, 'error.message', 'An unknown Threads error occurred.');

        if ($errorCode === 190) {
            throw new TokenExpiredException(
                message: $errorMessage,
                platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
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
}
