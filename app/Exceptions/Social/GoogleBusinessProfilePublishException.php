<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\Client\Response;

class GoogleBusinessProfilePublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $message = (string) data_get($response->json(), 'error.message', 'Google Business Profile rejected the post.');
        $code = (string) data_get($response->json(), 'error.status', $response->status());

        if ($response->status() === 401) {
            throw new TokenExpiredException($message, platformErrorCode: $code);
        }

        if ($response->status() === 429 || $response->serverError()) {
            $redactedBody = TokenRedactor::redact($response->body());

            throw new PlatformUnavailableException(
                "Google Business Profile API temporarily failed ({$response->status()}).",
                $response->status(),
                array_filter([
                    'platform_error_code' => $code,
                    'google_error_status' => data_get($response->json(), 'error.status'),
                    'google_error_message' => $message,
                    'raw_response' => $redactedBody === null ? null : mb_substr($redactedBody, 0, 2000),
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            );
        }

        $category = match (true) {
            $response->status() === 403 => ErrorCategory::Permission,
            default => ErrorCategory::ContentPolicy,
        };

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $code,
            rawResponse: $response->body(),
        );
    }

    public function platform(): string
    {
        return 'google-business-profile';
    }
}
