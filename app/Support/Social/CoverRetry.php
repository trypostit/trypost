<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Arr;
use Illuminate\Support\Number;

/**
 * How a cover applied to a video already live is tried again after a failure
 * a later try may fix (the network down, a server error, a rate limit): after
 * 1, 2, 5 and 10 minutes. A later reset time the network sent wins, up to an
 * hour. After the last try the post keeps the network's own cover and a warning.
 */
final class CoverRetry
{
    /**
     * @var list<int>
     */
    public const array BACKOFF_SECONDS = [60, 120, 300, 600];

    /** The first try and one more per wait. */
    public const int TRIES = 5;

    public const int MAX_DELAY_SECONDS = 3600;

    /**
     * The wait after the given try (counted from 1), or until the network's
     * own reset when it is later.
     */
    public static function delayAfter(int $attempt, ?int $networkDelaySeconds = null): int
    {
        $backoff = Arr::get(self::BACKOFF_SECONDS, $attempt - 1, Arr::last(self::BACKOFF_SECONDS));

        if ($networkDelaySeconds === null) {
            return $backoff;
        }

        return (int) Number::clamp($networkDelaySeconds, $backoff, self::MAX_DELAY_SECONDS);
    }
}
