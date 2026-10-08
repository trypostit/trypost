<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use App\Services\Social\Meta\GraphError;
use Illuminate\Http\Client\Response;

class InstagramPublishException extends SocialPublishException
{
    private const int MEDIA_NOT_READY_SUBCODE = 2207027;

    /**
     * Subcodes Instagram documents as caused by the user's media, caption,
     * tags, cover offset, account or daily limit. 2207005 (its documented
     * fix is a permission or token), 2207023 (the media_type we send), the
     * product tag subcodes (we never send product tags) and every server
     * subcode stay reported.
     *
     * @var list<int>
     */
    private const array USER_REJECTION_SUBCODES = [
        2207026, 2207004, 2207009, 2207057, 2207010, 2207028, 2207051,
        2207040, 2207042, 2207050,
    ];

    /**
     * media_publish answered that the container is not publishable yet: the
     * documented action is to wait for FINISHED and publish again.
     */
    public static function isMediaNotReady(Response $response): bool
    {
        return (int) $response->json('error.error_subcode') === self::MEDIA_NOT_READY_SUBCODE;
    }

    /**
     * A documented subcode whose fix is the user's, not a wait. Instagram
     * sends some of them under Graph codes that are otherwise transient (1
     * and 4), so this is checked before retrying.
     */
    public static function isDocumentedRejection(Response $response): bool
    {
        $subcode = $response->json('error.error_subcode');
        $mapped = self::forSubcode(is_numeric($subcode) ? (int) $subcode : null);

        return $mapped !== null && $mapped[1] !== ErrorCategory::ServerError;
    }

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

        $subcode = is_numeric($errorSubcode) ? (int) $errorSubcode : null;
        $mapped = self::forSubcode($subcode);

        [$message, $category] = $mapped
            ?? (in_array($errorCode, GraphError::RATE_LIMIT_CODES, true)
                ? ['Too many API calls. Please try again later.', ErrorCategory::RateLimit]
                : [$errorUserMsg ?? $errorMessage, ErrorCategory::Unknown]);

        $causedByUser = $mapped !== null
            ? in_array($subcode, self::USER_REJECTION_SUBCODES, true)
            : in_array($errorCode, GraphError::ACCOUNT_RATE_LIMIT_CODES, true);

        return (new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $errorSubcode !== null ? (string) $errorSubcode : ($category === ErrorCategory::RateLimit && $errorCode !== null ? (string) $errorCode : null),
            rawResponse: $rawResponse,
        ))->withNetworkReset($response)->asNetworkRejectionIf($causedByUser);
    }

    /**
     * The user message and category Instagram's documented error subcodes map to.
     *
     * @return array{string, ErrorCategory}|null
     */
    private static function forSubcode(?int $subcode): ?array
    {
        return match ($subcode) {
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
            default => null,
        };
    }

    /**
     * A container that reached status_code ERROR. Only a status that is a
     * bare error subcode is mapped; any other status is free text.
     */
    public static function fromContainerStatus(mixed $status, ?string $rawResponse): self
    {
        $subcode = is_int($status) || (is_string($status) && ctype_digit(trim($status))) ? (int) $status : null;
        $mapped = self::forSubcode($subcode);

        return (new self(
            userMessage: $mapped[0] ?? __('posts.errors.instagram.processing_failed'),
            category: $mapped[1] ?? ErrorCategory::Unknown,
            platformErrorCode: $subcode !== null ? (string) $subcode : null,
            rawResponse: $rawResponse,
        ))->asNetworkRejectionIf(in_array($subcode, self::USER_REJECTION_SUBCODES, true));
    }

    public function platform(): string
    {
        return 'instagram';
    }
}
