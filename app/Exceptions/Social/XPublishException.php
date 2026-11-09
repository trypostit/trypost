<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Enums\SocialAccount\Platform;
use App\Enums\X\ProblemType;
use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class XPublishException extends SocialPublishException
{
    /**
     * Problem types X documents as caused by the account itself:
     * not-authorized-for-resource is "No access to private/protected
     * content". invalid-request (our request), resource-not-found (an id we
     * sent), client-forbidden (our app's enrollment), usage-capped (our app's
     * cap) and rate-limit-exceeded (per user or per app) stay reported.
     *
     * @var list<ProblemType>
     */
    private const array USER_REJECTION_TYPES = [ProblemType::NotAuthorizedForResource];

    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();
        $statusCode = $response->status();

        $title = data_get($body, 'title', '');
        $detail = data_get($body, 'detail', $title);
        $problem = ProblemType::of($body);
        $problemCode = $problem?->value ?? data_get($body, 'type');

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $detail ?: 'Access token has expired or been revoked',
                platformErrorCode: $problemCode ?: (string) $statusCode,
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

        if ($statusCode === 429 && ! in_array($problem, [ProblemType::UsageCapped, ProblemType::RateLimitExceeded], true)) {
            return (new static(
                userMessage: 'Rate limit exceeded. Please try again later.',
                category: ErrorCategory::RateLimit,
                platformErrorCode: (string) $statusCode,
                rawResponse: $rawResponse,
            ))->withNetworkReset($response);
        }

        [$message, $category] = match ($problem) {
            ProblemType::UsageCapped => ['Usage limit exceeded. Please try again later.', ErrorCategory::RateLimit],
            ProblemType::RateLimitExceeded => ['Rate limit exceeded. Please try again later.', ErrorCategory::RateLimit],
            ProblemType::InvalidRequest => ['Invalid request. Check your post content.', ErrorCategory::ContentPolicy],
            ProblemType::ClientForbidden => ['App not enrolled or lacks required access.', ErrorCategory::Permission],
            ProblemType::NotAuthorizedForResource => ['Not authorized for this resource.', ErrorCategory::Permission],
            ProblemType::ResourceNotFound => ['Resource not found.', ErrorCategory::ContentPolicy],
            default => [self::providerMessage($response, 'detail', 'title') ?? __('posts.errors.unrecognized_error', ['platform' => Platform::X->label()]), ErrorCategory::Unknown],
        };

        return (new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $problemCode ?: null,
            rawResponse: $rawResponse,
        ))->withNetworkReset($response)->asNetworkRejectionIf(in_array($problem, self::USER_REJECTION_TYPES, true));
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
        return $response->status() === 401 || ProblemType::of($response->json()) === ProblemType::UnsupportedAuthentication;
    }
}
