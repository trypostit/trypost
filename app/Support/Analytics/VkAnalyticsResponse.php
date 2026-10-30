<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use Illuminate\Http\Client\Response;

/**
 * VK reports failures as HTTP 200 with an `error` object in the body, so the
 * collectors' HTTP-status mapping never sees them. This unwraps the `response`
 * payload and maps VK error codes onto the analytics exception categories.
 */
class VkAnalyticsResponse
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws AnalyticsCollectionException
     */
    public static function data(Response $response, string $operation): array
    {
        $error = $response->json('error');

        if ($error === null) {
            return (array) $response->json('response');
        }

        $code = (int) data_get($error, 'error_code');
        $message = (string) data_get($error, 'error_msg', 'unknown error');

        $category = match (true) {
            $code === 5 => 'authentication',
            in_array($code, [6, 9, 29], true) => 'rate_limited',
            in_array($code, [7, 15, 27, 30, 200, 203], true) => 'permission',
            in_array($code, [1, 10], true) => 'transient',
            default => 'malformed',
        };

        throw new AnalyticsCollectionException(
            $category,
            "{$operation} failed with VK error {$code}: {$message}",
            // 18 = page deleted or banned: the wall is gone for good.
            gone: $code === 18,
        );
    }

    /**
     * The wall to read: negative id for a community, positive for a profile.
     * Stored at connect time; platform_user_id keeps the same value as a
     * fallback for rows created before meta existed (same rule as VkPublisher).
     */
    public static function ownerId(SocialAccount $account): int
    {
        return (int) (data_get($account->meta, 'owner_id') ?? $account->platform_user_id);
    }
}
