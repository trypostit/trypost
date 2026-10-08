<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\TokenExpiredException;
use App\Services\Social\Meta\GraphError;
use Illuminate\Http\Client\Response;

class FacebookPublishException extends SocialPublishException
{
    /**
     * Graph codes that reject the Page feed `link` and leave the caption
     * untouched: 1609005 (could not scrape the URL) and 1500 (invalid URL).
     *
     * @var list<string>
     */
    private const array LINK_REJECTION_CODES = ['1609005', '1500'];

    /**
     * Subcode under a code 200 "Permissions error" that means the `link`
     * pointed at facebook.com, which Pages may not share through the API.
     */
    private const string FACEBOOK_URL_SUBCODE = '1609008';

    /**
     * Graph codes the Video API error reference documents as the Page's own
     * file (size, length, format) plus the duplicate post and the account's
     * own limits. 6000 ("retry and report bug", and what finishing an empty
     * session of ours returns), 1363042 (fixed with a valid token), the
     * upload-session failures, "no video file" (our request), editing a video
     * (ours), every code missing from Meta's references and the app-level
     * limits 4, 32, 341 and 613 stay reported.
     *
     * @see https://developers.facebook.com/documentation/video-api/reference/error-codes
     *
     * @var list<int>
     */
    private const array USER_REJECTION_CODES = [
        1363023, 1363022, 1363031, 1363032, 1363024, 1363025, 1363026, 506,
        ...GraphError::ACCOUNT_RATE_LIMIT_CODES,
    ];

    public function __construct(
        string $userMessage,
        ErrorCategory $category,
        ?string $platformErrorCode = null,
        ?string $rawResponse = null,
        public readonly ?string $platformErrorSubcode = null,
    ) {
        parent::__construct($userMessage, $category, $platformErrorCode, $rawResponse);
    }

    /**
     * A resumable upload (rupload) failure Meta asks to retry: a 5xx or a
     * failure flagged retriable.
     */
    public static function isRetryableUpload(Response $response): bool
    {
        return $response->serverError()
            || data_get($response->json(), 'debug_info.retriable') === true;
    }

    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();

        if (data_get($body, 'error') === null && filled(data_get($body, 'debug_info'))) {
            $uploadErrorType = data_get($body, 'debug_info.type');

            [$message, $category] = match ($uploadErrorType) {
                'ProcessingFailedError' => [__('posts.errors.facebook.processing_failed'), ErrorCategory::MediaFormat],
                'PartialRequestError', 'OffsetInvalidError' => [__('posts.errors.facebook.upload_incomplete'), ErrorCategory::ServerError],
                default => [__('posts.errors.unrecognized_error', ['platform' => Platform::Facebook->label()]), ErrorCategory::Unknown],
            };

            return new static(
                userMessage: $message,
                category: $category,
                platformErrorCode: is_string($uploadErrorType) && $uploadErrorType !== '' ? $uploadErrorType : null,
                rawResponse: $rawResponse,
            );
        }

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
            1609008 => ['Video format not supported for Reels.', ErrorCategory::MediaFormat],
            1366046 => ['Reels require a video.', ErrorCategory::ContentPolicy],
            1349125 => ['Rate limit exceeded. Try again later.', ErrorCategory::RateLimit],
            4, 32, 341, 613, 80001 => ['Too many API calls. Please try again later.', ErrorCategory::RateLimit],
            17 => ['User call limit reached.', ErrorCategory::RateLimit],
            506 => ['Duplicate post detected. Please modify content.', ErrorCategory::ContentPolicy],
            default => [$errorMessage, ErrorCategory::Unknown],
        };

        return (new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
            rawResponse: $rawResponse,
            platformErrorSubcode: $errorSubcode !== null ? (string) $errorSubcode : null,
        ))->withNetworkReset($response)->asNetworkRejectionIf(in_array($errorCode, self::USER_REJECTION_CODES, true));
    }

    /**
     * Whether Graph rejected the `link` rather than the post. The same caption
     * publishes as plain text once the link is dropped.
     */
    public function rejectsLink(): bool
    {
        return in_array($this->platformErrorCode, self::LINK_REJECTION_CODES, true)
            || ($this->platformErrorCode === '200' && $this->platformErrorSubcode === self::FACEBOOK_URL_SUBCODE);
    }

    public function platform(): string
    {
        return 'facebook';
    }
}
