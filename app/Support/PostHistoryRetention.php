<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

class PostHistoryRetention
{
    public static function days(): int
    {
        $value = config('trypost.posts.history_retention_days');

        if (is_string($value)) {
            $value = trim($value);
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1 || (int) $value < 1) {
            throw new InvalidArgumentException('POST_HISTORY_RETENTION_DAYS must be a whole number of days, 1 or more.');
        }

        return (int) $value;
    }
}
