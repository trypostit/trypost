<?php

declare(strict_types=1);

namespace App\Enums\GoogleBusiness;

/**
 * Google Business Profile `LocalPost.state` values from Local Posts v4.
 *
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.localPosts#LocalPostState
 */
enum LocalPostState: string
{
    case Unspecified = 'LOCAL_POST_STATE_UNSPECIFIED';
    case Rejected = 'REJECTED';
    case Live = 'LIVE';
    case Processing = 'PROCESSING';
    case Scheduled = 'SCHEDULED';
    case Recurring = 'RECURRING';

    /**
     * Missing or unknown Google state is still in review — fail closed so we
     * keep the JPEG and do not mark the target published.
     */
    public static function fromApi(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Processing;
    }

    public function isPendingReview(): bool
    {
        return match ($this) {
            self::Processing, self::Scheduled => true,
            default => false,
        };
    }

    public function isLive(): bool
    {
        return match ($this) {
            self::Live, self::Recurring => true,
            default => false,
        };
    }

    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }
}
