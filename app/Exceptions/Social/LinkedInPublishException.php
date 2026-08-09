<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class LinkedInPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();
        $statusCode = $response->status();

        $errorMessage = data_get($body, 'message', $rawResponse);

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $errorMessage ?? 'Access token has expired or been revoked',
                platformErrorCode: (string) $statusCode,
            );
        }

        if (str_contains((string) $rawResponse, 'Unable to obtain activity')) {
            return new static(
                userMessage: 'LinkedIn server error. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            );
        }

        if (str_contains((string) $rawResponse, 'resource is forbidden')) {
            return new static(
                userMessage: 'Not authorized to post to this account.',
                category: ErrorCategory::Permission,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            );
        }

        [$message, $category] = match ($statusCode) {
            403 => ['Not authorized to post to this account.', ErrorCategory::Permission],
            422 => ['Invalid post data. Please check your content.', ErrorCategory::ContentPolicy],
            default => [$errorMessage ?? 'An unknown LinkedIn error occurred.', ErrorCategory::Unknown],
        };

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: (string) $statusCode,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'linkedin';
    }

    /**
     * Whether this response confirms the account's own access_token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead LinkedIn/LinkedIn Page token looks like.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        return $response->status() === 401;
    }
}
