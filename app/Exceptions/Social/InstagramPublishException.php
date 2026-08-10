<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class InstagramPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();

        $errorCode = data_get($body, 'error.code');
        $errorSubcode = data_get($body, 'error.error_subcode');
        $errorUserMsg = data_get($body, 'error.error_user_msg');
        $errorMessage = data_get($body, 'error.message', 'An unknown Instagram error occurred.');

        if ($errorCode === 190) {
            throw new TokenExpiredException(
                message: $errorMessage,
                platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
            );
        }

        [$message, $category] = match ($errorSubcode) {
            2207026 => ['Unsupported video format. Please upload MP4 or MOV.', ErrorCategory::MediaFormat],
            2207005 => ['Unsupported image format.', ErrorCategory::MediaFormat],
            2207004 => ['Image is too large (max 8MB).', ErrorCategory::MediaFormat],
            2207009 => ['Aspect ratio not supported (must be between 4:5 and 1.91:1).', ErrorCategory::MediaFormat],
            2207057 => ['Thumbnail offset is outside the video duration.', ErrorCategory::MediaFormat],
            2207023 => ['Unknown media type.', ErrorCategory::MediaFormat],
            2207003 => ['Media download timed out. Please try again.', ErrorCategory::ServerError],
            2207020 => ['Media has expired. Please upload again.', ErrorCategory::ServerError],
            2207032 => ['Failed to create media. Please try again.', ErrorCategory::ServerError],
            2207053 => ['Unknown upload error. Please try again.', ErrorCategory::ServerError],
            2207052 => ['Could not fetch media from URL. Please try again.', ErrorCategory::ServerError],
            2207006 => ['Media not found. Please upload again.', ErrorCategory::ServerError],
            2207008 => ['Media container expired. Please try again in a few minutes.', ErrorCategory::ServerError],
            2207027 => ['Media is not ready for publishing. Please wait and try again.', ErrorCategory::ServerError],
            2207001 => ['Instagram server error. Please try again.', ErrorCategory::ServerError],
            2207010 => ['Caption is too long (max 2,200 characters, 30 hashtags, 20 @mentions).', ErrorCategory::ContentPolicy],
            2207028 => ['Carousel needs between 2 and 10 photos/videos.', ErrorCategory::ContentPolicy],
            2207051 => ['Instagram restricted this action to protect the community.', ErrorCategory::ContentPolicy],
            2207035 => ['Product tag positions are not supported for videos.', ErrorCategory::ContentPolicy],
            2207036 => ['Product tag positions are required for photos.', ErrorCategory::ContentPolicy],
            2207037 => ['Invalid product tag. The product may be deleted or not permitted.', ErrorCategory::ContentPolicy],
            2207040 => ['Too many tags (max 20).', ErrorCategory::ContentPolicy],
            2207042 => ['Daily publishing limit reached. Please try again tomorrow.', ErrorCategory::RateLimit],
            2207050 => ['Instagram account is restricted or inactive. Please check the Instagram app.', ErrorCategory::Permission],
            2207081 => ["This account doesn't support Trial Reels.", ErrorCategory::Permission],
            default => [$errorUserMsg ?? $errorMessage, ErrorCategory::Unknown],
        };

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $errorSubcode !== null ? (string) $errorSubcode : null,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'instagram';
    }
}
