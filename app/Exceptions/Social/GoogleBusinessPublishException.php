<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class GoogleBusinessPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $status = $response->status();
        $reason = (string) data_get($response->json(), 'error.status', '');
        $message = (string) data_get($response->json(), 'error.message', '');
        $rawResponse = $response->body();

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $message !== '' ? $message : 'Google Business Profile access token has expired or been revoked',
                platformErrorCode: $reason !== '' ? $reason : (string) $status,
            );
        }

        if ($reason === 'PERMISSION_DENIED') {
            return new static(
                userMessage: 'Permission denied. Please reconnect and confirm access to this business location.',
                category: ErrorCategory::Permission,
                platformErrorCode: $reason,
                rawResponse: $rawResponse,
            );
        }

        if ($reason === 'NOT_FOUND') {
            return new static(
                userMessage: 'Business location not found. It may have been deleted — please reconnect.',
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: $reason,
                rawResponse: $rawResponse,
            );
        }

        if ($reason === 'INVALID_ARGUMENT') {
            return new static(
                userMessage: $message !== '' ? $message : 'Invalid post content. Please check your post details.',
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: $reason,
                rawResponse: $rawResponse,
            );
        }

        if ($reason === 'RESOURCE_EXHAUSTED' || $status === 429) {
            return new static(
                userMessage: 'Rate limit exceeded. Please try again later.',
                category: ErrorCategory::RateLimit,
                platformErrorCode: $reason !== '' ? $reason : (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status >= 500) {
            return new static(
                userMessage: 'Google Business Profile server error. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: $message !== '' ? $message : $rawResponse,
            category: ErrorCategory::Unknown,
            platformErrorCode: $reason !== '' ? $reason : (string) $status,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'google_business';
    }

    /**
     * Whether this response confirms the account's own access_token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead Google Business Profile token looks like.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        return $response->status() === 401
            || data_get($response->json(), 'error.status') === 'UNAUTHENTICATED';
    }
}
