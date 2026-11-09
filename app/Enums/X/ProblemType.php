<?php

declare(strict_types=1);

namespace App\Enums\X;

use Illuminate\Support\Arr;
use Illuminate\Support\Uri;

/**
 * The problem types X documents for its API errors, named by the `type` URI
 * `https://api.x.com/2/problems/<value>` (api.twitter.com before the move).
 *
 * @see https://docs.x.com/x-api/fundamentals/response-codes-and-errors
 */
enum ProblemType: string
{
    case ClientForbidden = 'client-forbidden';
    case InvalidRequest = 'invalid-request';
    case NotAuthorizedForResource = 'not-authorized-for-resource';
    case RateLimitExceeded = 'rate-limit-exceeded';
    case ResourceNotFound = 'resource-not-found';
    case UnsupportedAuthentication = 'unsupported-authentication';
    case UsageCapped = 'usage-capped';

    /**
     * The problem a response body, or one of its `errors`, names in its `type`.
     */
    public static function of(mixed $problem): ?self
    {
        $path = Uri::of((string) data_get($problem, 'type'))->path();

        return Arr::first(self::cases(), fn (self $case): bool => $path === "2/problems/{$case->value}");
    }
}
