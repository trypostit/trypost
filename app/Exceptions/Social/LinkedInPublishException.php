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

        if ($statusCode === 429) {
            return (new static(
                userMessage: 'LinkedIn rate limit reached. Please try again later.',
                category: ErrorCategory::RateLimit,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            ))->withNetworkReset($response);
        }

        if ($response->serverError()) {
            return new static(
                userMessage: __('posts.errors.linkedin.server_error'),
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            );
        }

        [$message, $category] = match ($statusCode) {
            403 => ['Not authorized to post to this account.', ErrorCategory::Permission],
            422 => ['Invalid post data. Please check your content.', ErrorCategory::ContentPolicy],
            default => [__('posts.errors.unrecognized_error', ['platform' => 'LinkedIn']), ErrorCategory::Unknown],
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
