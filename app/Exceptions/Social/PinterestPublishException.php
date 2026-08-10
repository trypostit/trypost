<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class PinterestPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $status = $response->status();
        $body = $response->json();
        $rawResponse = $response->body();

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: data_get($body, 'message', 'Access token has expired or been revoked'),
                platformErrorCode: (string) $status,
            );
        }

        if ($status === 403) {
            return new static(
                userMessage: 'Not authorized to create pins on this board.',
                category: ErrorCategory::Permission,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status === 429) {
            return new static(
                userMessage: 'Rate limit exceeded. Please try again later.',
                category: ErrorCategory::RateLimit,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status === 400 && str_contains(strtolower($rawResponse), 'board')) {
            return new static(
                userMessage: 'Invalid board. Please select a valid board.',
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status >= 500) {
            return new static(
                userMessage: 'Pinterest server error. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: $rawResponse,
            category: ErrorCategory::Unknown,
            platformErrorCode: (string) $status,
            rawResponse: $rawResponse,
        );
    }

    public static function fromProcessingStatus(string $status, ?string $rawResponse = null): static
    {
        if ($status === 'failed') {
            return new static(
                userMessage: 'Media processing failed. Please try a different file.',
                category: ErrorCategory::MediaFormat,
                platformErrorCode: null,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: $rawResponse ?? 'An unknown Pinterest error occurred.',
            category: ErrorCategory::Unknown,
            platformErrorCode: null,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'pinterest';
    }

    /**
     * Whether this response confirms the account's own access_token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead Pinterest token looks like.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        return $response->status() === 401;
    }
}
