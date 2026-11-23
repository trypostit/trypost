<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\TokenExpiredException;
use App\Services\Social\Meta\GraphError;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

class InstagramPublishException extends SocialPublishException
{
    private const int MEDIA_NOT_READY_SUBCODE = 2207027;

    private const string UNKNOWN_ERROR_MESSAGE = 'An unknown Instagram error occurred.';

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
        return self::numericSubcode($response->json('error.error_subcode')) === self::MEDIA_NOT_READY_SUBCODE;
    }

    /**
     * A documented subcode whose fix is the user's, not a wait. Instagram
     * sends some of them under Graph codes that are otherwise transient (1
     * and 4), so this is checked before retrying.
     */
    public static function isDocumentedRejection(Response $response): bool
    {
        $subcode = self::numericSubcode($response->json('error.error_subcode'));

        return transform(
            self::forSubcode($subcode),
            function (array $error): bool {
                [, $category] = $error;

                return $category !== ErrorCategory::ServerError;
            },
            false,
        );
    }

    public static function isRateLimit(Response $response): bool
    {
        return $response->tooManyRequests()
            || collect(GraphError::RATE_LIMIT_CODES)->containsStrict($response->json('error.code'));
    }

    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $errorCode = $response->json('error.code');

        if ($errorCode === 190) {
            throw new TokenExpiredException(
                message: $response->json('error.message', self::UNKNOWN_ERROR_MESSAGE),
                platformErrorCode: (string) $errorCode,
            );
        }

        $subcode = $response->json('error.error_subcode');
        [$message, $category, $causedByUser] = self::classifyResponse($response, self::numericSubcode($subcode));
        $rateLimitCode = $category === ErrorCategory::RateLimit ? $errorCode : null;
        $platformErrorCode = filled($subcode) ? $subcode : $rateLimitCode;

        return (new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: transform($platformErrorCode, strval(...)),
            rawResponse: $response->body(),
        ))->withNetworkReset($response)
            ->asNetworkRejectionIf($causedByUser);
    }

    /** @return array{string, ErrorCategory, bool} */
    private static function classifyResponse(Response $response, ?int $subcode): array
    {
        $documentedError = self::forSubcode($subcode);

        if (filled($documentedError)) {
            return [...$documentedError, self::isUserRejectionSubcode($subcode)];
        }

        if (self::isRateLimit($response)) {
            return [
                'Too many API calls. Please try again later.',
                ErrorCategory::RateLimit,
                collect(GraphError::ACCOUNT_RATE_LIMIT_CODES)->containsStrict($response->json('error.code')),
            ];
        }

        $message = $response->json('error.error_user_msg')
            ?? $response->json('error.message', self::UNKNOWN_ERROR_MESSAGE);

        return [$message, ErrorCategory::Unknown, false];
    }

    private static function numericSubcode(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function isUserRejectionSubcode(?int $subcode): bool
    {
        return collect(self::USER_REJECTION_SUBCODES)->containsStrict($subcode);
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
            self::MEDIA_NOT_READY_SUBCODE => ['Media is not ready for publishing. Please wait and try again.', ErrorCategory::ServerError],
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
        $subcode = self::containerSubcode($status);
        [$message, $category] = self::forSubcode($subcode)
            ?? [__('posts.errors.instagram.processing_failed'), ErrorCategory::Unknown];

        return (new self(
            userMessage: $message,
            category: $category,
            platformErrorCode: transform($subcode, strval(...)),
            rawResponse: $rawResponse,
        ))->asNetworkRejectionIf(self::isUserRejectionSubcode($subcode));
    }

    private static function containerSubcode(mixed $status): ?int
    {
        if (is_int($status)) {
            return $status;
        }

        if (! is_string($status)) {
            return null;
        }

        $digits = Str::trim($status);

        return ctype_digit($digits) ? (int) $digits : null;
    }

    public function platform(): string
    {
        return Platform::Instagram->value;
    }
}
