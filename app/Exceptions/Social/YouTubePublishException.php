<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Http\Client\Response;

class YouTubePublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();

        $reason = data_get($body, 'error.errors.0.reason');
        $fallbackMessage = data_get($body, 'error.message', 'An unknown YouTube error occurred.');

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $fallbackMessage,
                platformErrorCode: $reason,
            );
        }

        [$message, $category] = self::mapReasonToMessageAndCategory(
            reason: $reason,
            fallbackMessage: $fallbackMessage,
        );

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $reason,
            rawResponse: $rawResponse,
        );
    }

    public static function fromGoogleException(GoogleServiceException $e): static
    {
        $errors = $e->getErrors();
        $reason = data_get($errors, '0.reason');
        $rawMessage = data_get($errors, '0.message', $e->getMessage());

        if ($e->getCode() === 401 || in_array($reason, ['authError', 'unauthorized'], true)) {
            throw new TokenExpiredException(
                message: $rawMessage,
                platformErrorCode: $reason,
            );
        }

        [$message, $category] = self::mapReasonToMessageAndCategory(
            reason: $reason,
            fallbackMessage: $rawMessage,
        );

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $reason,
            rawResponse: $e->getMessage(),
        );
    }

    public function platform(): string
    {
        return 'youtube';
    }

    /**
     * Whether this response confirms the account's own access_token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead YouTube token looks like.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        return $response->status() === 401;
    }

    /**
     * @return array{string, ErrorCategory}
     */
    private static function mapReasonToMessageAndCategory(?string $reason, string $fallbackMessage): array
    {
        return match ($reason) {
            'invalidTitle' => ['Video title is invalid or empty.', ErrorCategory::ContentPolicy],
            'invalidDescription' => ['Video description is invalid.', ErrorCategory::ContentPolicy],
            'invalidTags' => ['Video tags are invalid.', ErrorCategory::ContentPolicy],
            'invalidCategoryId' => ['Video category is invalid.', ErrorCategory::ContentPolicy],
            'invalidVideoMetadata' => ['Video metadata is invalid. Title and category are required.', ErrorCategory::ContentPolicy],
            'invalidPublishAt' => ['Scheduled publishing time is invalid.', ErrorCategory::ContentPolicy],
            'invalidRecordingDetails' => ['Recording details are invalid.', ErrorCategory::ContentPolicy],
            'invalidVideoGameRating' => ['Video game rating is invalid.', ErrorCategory::ContentPolicy],
            'invalidFilename' => ['Video filename is invalid.', ErrorCategory::MediaFormat],
            'mediaBodyRequired' => ['Video file is missing from the request.', ErrorCategory::MediaFormat],
            'failedPrecondition' => ['Thumbnail too large or account not verified.', ErrorCategory::MediaFormat],
            'uploadLimitExceeded' => ['Daily upload limit reached. Try again tomorrow.', ErrorCategory::RateLimit],
            'forbidden' => ["You don't have permission to upload to this channel.", ErrorCategory::Permission],
            'forbiddenLicenseSetting' => ['Invalid video license setting.', ErrorCategory::Permission],
            'forbiddenPrivacySetting' => ['Invalid video privacy setting.', ErrorCategory::Permission],
            default => [$fallbackMessage, ErrorCategory::Unknown],
        };
    }
}
