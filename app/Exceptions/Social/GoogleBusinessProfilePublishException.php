<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
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
            throw new PlatformUnavailableException(
                "Google Business Profile API temporarily failed ({$response->status()}).",
                $response->status(),
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
