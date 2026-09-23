<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\ObservationProvenance;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class AbstractFollowerCollector
{
    protected function get(
        SocialAccount $account,
        string $url,
        array $query = [],
        bool $meta = false,
    ): Response {
        $response = Http::acceptJson()
            ->withToken($account->access_token)
            ->timeout(120)
            ->get($url, $query);

        if ($response->successful()) {
            return $response;
        }

        $code = (int) data_get($response->json(), 'error.code', 0);
        $isMetaRateLimit = $meta && in_array($code, [4, 17, 32, 80001, 80002], true);
        $category = match (true) {
            $response->status() === 429, $isMetaRateLimit => 'rate_limited',
            $response->status() === 401 => 'authentication',
            $response->status() === 403 => 'permission',
            $response->serverError(), $meta && in_array($code, [1, 2], true) => 'transient',
            default => 'malformed',
        };

        throw new AnalyticsCollectionException(
            $category,
            "follower collection failed with HTTP {$response->status()}",
            RetryAfter::from($response),
        );
    }

    protected function observation(
        CarbonImmutable $date,
        mixed $value,
        MetricPrecision $precision = MetricPrecision::Exact,
    ): AccountDailyObservation {
        if (! is_numeric($value)) {
            throw AnalyticsCollectionException::malformed('missing follower metric');
        }

        return new AccountDailyObservation(
            date: $date,
            followers: (int) $value,
            provenance: ObservationProvenance::Actual,
            precision: $precision,
            providerObservedAt: CarbonImmutable::now('UTC'),
            collectedAt: CarbonImmutable::now('UTC'),
        );
    }
}
