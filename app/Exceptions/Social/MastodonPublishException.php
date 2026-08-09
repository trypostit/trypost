<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class MastodonPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $status = $response->status();
        $body = $response->json();
        $rawResponse = $response->body();

        $errorMessage = data_get($body, 'error', 'An unknown Mastodon error occurred.');

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $errorMessage,
                platformErrorCode: (string) $status,
            );
        }

        if ($status === 403) {
            return new static(
                userMessage: 'This action is not allowed.',
                category: ErrorCategory::Permission,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status === 422 && str_contains(strtolower($rawResponse), "text can't be blank")) {
            return new static(
                userMessage: 'Post text is required when no media is attached.',
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status === 422) {
            return new static(
                userMessage: 'Media validation failed.',
                category: ErrorCategory::MediaFormat,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status === 413) {
            return new static(
                userMessage: 'File is too large for this Mastodon instance.',
                category: ErrorCategory::MediaFormat,
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

        if ($status === 503) {
            return new static(
                userMessage: 'Mastodon server error. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: $errorMessage,
            category: ErrorCategory::Unknown,
            platformErrorCode: (string) $status,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'mastodon';
    }

    /**
     * Whether this response confirms the account's own access_token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead Mastodon token looks like.
     *
     * 403 is deliberately NOT included here: on the write-scoped /statuses
     * endpoint a 403 can mean the app only has read scope, which doesn't
     * prove the token itself is dead (see 'mastodon publisher throws
     * permission exception on forbidden'). ConnectionVerifier adds its own
     * 403 check on top of this one, because verify_credentials is the
     * lowest-privilege read endpoint — a 403 there means the token has no
     * access at all, a stronger and different signal than a write-scope 403.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        return $response->status() === 401;
    }
}
