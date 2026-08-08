<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class FacebookPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();

        $errorCode = data_get($body, 'error.code');
        $errorSubcode = data_get($body, 'error.error_subcode');
        $errorMessage = data_get($body, 'error.message', 'An unknown Facebook error occurred.');

        $tokenSubcodes = [458, 459, 460, 463, 464, 467];

        if ($errorCode === 190 || in_array($errorSubcode, $tokenSubcodes, true)) {
            throw new TokenExpiredException(
                message: $errorMessage,
                platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
            );
        }

        [$message, $category] = match ($errorCode) {
            6000 => ['Problem with file. Try with another file.', ErrorCategory::MediaFormat],
            1363042 => ['No permission to upload video here.', ErrorCategory::Permission],
            1363023 => ['Video exceeds 2GB maximum size.', ErrorCategory::MediaFormat],
            1363022 => ['Video below 1KB minimum size.', ErrorCategory::MediaFormat],
            1363030 => ['Upload timed out. Please try again.', ErrorCategory::ServerError],
            1363019 => ['Problem uploading video. Please try again.', ErrorCategory::ServerError],
            1363031 => ['Unsupported file format.', ErrorCategory::MediaFormat],
            1363032 => ['File is not a valid video.', ErrorCategory::MediaFormat],
            1363024 => ['Unsupported video format.', ErrorCategory::MediaFormat],
            1363025 => ['Video is too short (minimum 1 second).', ErrorCategory::MediaFormat],
            1363026 => ['Video is too long (maximum 40 minutes).', ErrorCategory::MediaFormat],
            1363033 => ['Upload interrupted. Please try again.', ErrorCategory::ServerError],
            1363037 => ['Invalid upload offset.', ErrorCategory::ServerError],
            1363020 => ['No video file selected.', ErrorCategory::MediaFormat],
            1363045 => ['Upload size mismatch.', ErrorCategory::ServerError],
            1363041 => ['Upload session expired. Please try again.', ErrorCategory::ServerError],
            1363021 => ['Problem during video upload. Please try again.', ErrorCategory::ServerError],
            1363005 => ['No permission to edit this video.', ErrorCategory::Permission],
            1363047 => ['Reel encoding issue. Please try a different video.', ErrorCategory::MediaFormat],
            1609008 => ['Video format not supported for Reels.', ErrorCategory::MediaFormat],
            1609010 => ['Reel encoding requirements not met.', ErrorCategory::MediaFormat],
            1366046 => ['Reels require a video.', ErrorCategory::ContentPolicy],
            2061006 => ['Video is too short for this format.', ErrorCategory::MediaFormat],
            1390008 => ['Caption is too long.', ErrorCategory::ContentPolicy],
            1346003 => ['Thumbnail is incompatible.', ErrorCategory::ContentPolicy],
            1349125 => ['Rate limit exceeded. Try again later.', ErrorCategory::RateLimit],
            4 => ['Too many API calls. Please try again later.', ErrorCategory::RateLimit],
            17 => ['User call limit reached.', ErrorCategory::RateLimit],
            506 => ['Duplicate post detected. Please modify content.', ErrorCategory::ContentPolicy],
            default => [$errorMessage, ErrorCategory::Unknown],
        };

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'facebook';
    }
}
