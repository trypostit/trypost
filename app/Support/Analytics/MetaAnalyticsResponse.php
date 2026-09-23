<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use Illuminate\Http\Client\Response;

class MetaAnalyticsResponse
{
    public static function successful(Response $response, string $context): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $code = (int) data_get($response->json(), 'error.code', 0);
        $category = match (true) {
            $response->status() === 429, in_array($code, [4, 17, 32, 80001, 80002], true) => 'rate_limited',
            $response->status() === 401, $code === 190 => 'authentication',
            $response->status() === 403, in_array($code, [10, 200], true) => 'permission',
            $response->serverError(), in_array($code, [1, 2], true) => 'transient',
            default => 'malformed',
        };

        throw new AnalyticsCollectionException(
            $category,
            "{$context} failed with HTTP {$response->status()}",
            RetryAfter::from($response),
        );
    }
}
