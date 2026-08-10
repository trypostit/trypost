<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class BlueskyPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $status = $response->status();
        $body = $response->json();
        $rawResponse = $response->body();

        $error = data_get($body, 'error', '');
        $errorMessage = data_get($body, 'message', 'An unknown Bluesky error occurred.');

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $errorMessage,
                platformErrorCode: $error,
            );
        }

        if (str_contains($rawResponse, 'BlobTooLarge') || $status === 413) {
            return new static(
                userMessage: "Image exceeds Bluesky's 1MB limit.",
                category: ErrorCategory::MediaFormat,
                platformErrorCode: $error ?: null,
                rawResponse: $rawResponse,
            );
        }

        if ($error === 'InvalidRequest') {
            return new static(
                userMessage: 'Invalid post data.',
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: $error,
                rawResponse: $rawResponse,
            );
        }

        if ($status === 429) {
            return new static(
                userMessage: 'Rate limit exceeded. Please try again later.',
                category: ErrorCategory::RateLimit,
                platformErrorCode: $error ?: null,
                rawResponse: $rawResponse,
            );
        }

        if ($status >= 500) {
            return new static(
                userMessage: 'Bluesky server error. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: $error ?: null,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: $errorMessage,
            category: ErrorCategory::Unknown,
            platformErrorCode: $error ?: null,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'bluesky';
    }

    /**
     * Whether this response confirms the account's own session token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead Bluesky session looks like.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        $error = data_get($response->json(), 'error', '');

        return in_array($error, ['ExpiredToken', 'InvalidToken'], true);
    }
}
