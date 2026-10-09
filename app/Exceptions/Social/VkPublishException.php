<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class VkPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $rawResponse = $response->body();
        $error = $response->json('error');

        // VK reports failures as HTTP 200 with an `error` object; transport
        // failures (5xx) have no such object.
        $code = (int) data_get($error, 'error_code', 0);
        $message = (string) data_get($error, 'error_msg', 'An unknown VK error occurred.');

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $message,
                platformErrorCode: (string) $code,
            );
        }

        return new static(
            userMessage: $message,
            category: match (true) {
                in_array($code, [6, 9, 29], true) => ErrorCategory::RateLimit,
                in_array($code, [7, 15, 200, 214, 219], true) => ErrorCategory::Permission,
                in_array($code, [100, 118, 129], true) => ErrorCategory::MediaFormat,
                $code === 0 && $response->serverError() => ErrorCategory::ServerError,
                default => ErrorCategory::Unknown,
            },
            platformErrorCode: $code > 0 ? (string) $code : (string) $response->status(),
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'vk';
    }

    /**
     * Whether this response confirms the account's own access_token is dead.
     * VK error 5 is "User authorization failed" — the token was revoked or
     * invalidated (password change, security logout). Shared with
     * ConnectionVerifier so publish and verify agree on what a dead token
     * looks like.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        return (int) $response->json('error.error_code') === 5;
    }
}
