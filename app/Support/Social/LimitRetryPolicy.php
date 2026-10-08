<?php

declare(strict_types=1);

namespace App\Support\Social;

use Carbon\CarbonInterface;

/**
 * A publish a network refused for a limit (rate limit, posting quota, daily
 * cap) waits and tries again: after 1 hour, then 2, then 4. A later reset
 * time the network sent wins over the backoff, capped at 24 hours. After the
 * last retry is refused the target fails.
 */
final class LimitRetryPolicy
{
    /**
     * @var list<int>
     */
    public const array BACKOFF_SECONDS = [3600, 7200, 14400];

    public const int MAX_DELAY_SECONDS = 86400;

    public const string ATTEMPTS_KEY = 'limit_retries';

    /**
     * Keeps the scheduler's dispatches apart from the platform-unavailable
     * attempts in PublishToSocialPlatform's unique id.
     */
    public const int UNIQUE_ATTEMPT_OFFSET = 1000;

    /**
     * @param  array<string, mixed>|null  $context
     */
    public static function retriesSoFar(?array $context): int
    {
        return (int) data_get($context, self::ATTEMPTS_KEY, 0);
    }

    /**
     * When the next attempt runs, or null when the retries are spent.
     */
    public static function nextAttemptAt(int $retriesSoFar, ?CarbonInterface $networkResetAt = null): ?CarbonInterface
    {
        $backoff = self::BACKOFF_SECONDS[$retriesSoFar] ?? null;

        if ($backoff === null) {
            return null;
        }

        $retryAt = now()->addSeconds($backoff);

        if ($networkResetAt === null || $networkResetAt->lessThanOrEqualTo($retryAt)) {
            return $retryAt;
        }

        $ceiling = now()->addSeconds(self::MAX_DELAY_SECONDS);

        return $networkResetAt->greaterThan($ceiling) ? $ceiling : $networkResetAt->toImmutable();
    }
}
