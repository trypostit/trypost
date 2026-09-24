<?php

declare(strict_types=1);

namespace App\Support\Analytics;

class MetricComparison
{
    /** @return array{value: int|float|null, previous: int|float|null, change: ?float} */
    public static function between(int|float|null $current, int|float|null $previous): array
    {
        return [
            'value' => $current,
            'previous' => $previous,
            'change' => $current !== null && $previous !== null && $previous != 0
                ? round(($current - $previous) / $previous * 100, 2) : null,
        ];
    }
}
