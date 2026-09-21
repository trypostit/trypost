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
                message: $message !== '' ? $message : __('posts.errors.google_business.token_expired'),
                platformErrorCode: $reason !== '' ? $reason : (string) $status,
            );
        }

        if ($reason === 'PERMISSION_DENIED') {
            return new static(
                userMessage: __('posts.errors.google_business.permission_denied'),
                category: ErrorCategory::Permission,
                platformErrorCode: $reason,
                rawResponse: $rawResponse,
            );
        }

        if ($reason === 'NOT_FOUND') {
            return new static(
                userMessage: __('posts.errors.google_business.not_found'),
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: $reason,
                rawResponse: $rawResponse,
            );
        }

        if ($reason === 'INVALID_ARGUMENT') {
            return new static(
                userMessage: $message !== '' ? $message : __('posts.errors.google_business.invalid_content'),
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: $reason,
                rawResponse: $rawResponse,
            );
        }

        if ($reason === 'RESOURCE_EXHAUSTED' || $status === 429) {
            return new static(
                userMessage: __('posts.errors.google_business.rate_limited'),
                category: ErrorCategory::RateLimit,
                platformErrorCode: $reason !== '' ? $reason : (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status >= 500) {
            return new static(
                userMessage: __('posts.errors.google_business.server_error'),
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: __('posts.errors.google_business.rejected'),
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
