<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class XPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();
        $statusCode = $response->status();

        $type = data_get($body, 'type', '');
        $title = data_get($body, 'title', '');
        $detail = data_get($body, 'detail', $title);

        $typeSuffix = $type !== '' ? basename((string) $type) : '';

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $detail ?: 'Access token has expired or been revoked',
                platformErrorCode: $typeSuffix ?: (string) $statusCode,
            );
        }

        if (str_contains((string) $rawResponse, 'invalid URL')) {
            return new static(
                userMessage: 'Post contains an invalid URL.',
                category: ErrorCategory::ContentPolicy,
                platformErrorCode: $typeSuffix ?: null,
                rawResponse: $rawResponse,
            );
        }

        if (str_contains((string) $rawResponse, 'video longer than 2 minutes')) {
            return new static(
                userMessage: 'Video exceeds the 2-minute limit.',
                category: ErrorCategory::MediaFormat,
                platformErrorCode: $typeSuffix ?: null,
                rawResponse: $rawResponse,
            );
        }

        $firstErrorMessage = (string) data_get($body, 'errors.0.message', '');

        if (
            str_contains((string) $rawResponse, 'media IDs are invalid')
            || str_contains($firstErrorMessage, 'media IDs are invalid')
        ) {
            return new static(
                userMessage: 'X rejected the attached media. Please re-upload and try again.',
                category: ErrorCategory::MediaFormat,
                platformErrorCode: $typeSuffix ?: null,
                rawResponse: $rawResponse,
            );
        }

        if (
            str_contains((string) $rawResponse, 'Request body must be a JSON object')
            || str_contains($firstErrorMessage, 'Request body must be a JSON object')
        ) {
            return new static(
                userMessage: 'X rejected the media upload request. Please try again.',
                category: ErrorCategory::ServerError,
                platformErrorCode: $typeSuffix ?: null,
                rawResponse: $rawResponse,
            );
        }

        if ($statusCode === 413) {
            return new static(
                userMessage: 'Media chunk rejected by X (payload too large).',
                category: ErrorCategory::MediaFormat,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            );
        }

        if (in_array($statusCode, [500, 502, 503, 504], true)) {
            return new static(
                userMessage: 'X server error. Please try again later.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            );
        }

        [$message, $category] = match ($typeSuffix) {
            'usage-capped' => ['Usage limit exceeded. Please try again later.', ErrorCategory::RateLimit],
            'rate-limit-exceeded' => ['Rate limit exceeded. Please try again later.', ErrorCategory::RateLimit],
            'invalid-request' => ['Invalid request. Check your post content.', ErrorCategory::ContentPolicy],
            'client-forbidden' => ['App not enrolled or lacks required access.', ErrorCategory::Permission],
            'not-authorized-for-resource' => ['Not authorized for this resource.', ErrorCategory::Permission],
            'resource-not-found' => ['Resource not found.', ErrorCategory::ContentPolicy],
            default => [$detail ?: $title ?: 'An unknown X error occurred.', ErrorCategory::Unknown],
        };

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $typeSuffix ?: null,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'x';
    }

    /**
     * Whether this response confirms the account's own access_token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead X token looks like.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        $type = (string) data_get($response->json(), 'type', '');

        return $response->status() === 401 || str_contains($type, 'unsupported-authentication');
    }
}
