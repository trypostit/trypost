<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

class RetryAfter
{
    public static function from(Response $response): ?CarbonImmutable
    {
        $header = $response->header('Retry-After');

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            return CarbonImmutable::now('UTC')->addSeconds((int) $header);
        }

        try {
            return CarbonImmutable::parse($header)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
