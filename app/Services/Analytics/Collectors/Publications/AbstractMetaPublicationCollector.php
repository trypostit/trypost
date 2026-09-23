<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class AbstractMetaPublicationCollector
{
    /**
     * @param  array<string, mixed>  $query
     */
    protected function get(SocialAccount $account, string $url, array $query = []): Response
    {
        $response = Http::acceptJson()
            ->withToken($account->access_token)
            ->timeout(120)
            ->get($url, array_filter($query, fn (mixed $value): bool => $value !== null && $value !== ''));

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
            "publication history collection failed with HTTP {$response->status()}",
            $this->retryAt($response),
        );
    }

    /**
     * @param  list<DiscoveredPublication>  $publications
     */
    protected function result(
        array $publications,
        Response $response,
        bool $crossedCutoff,
        bool $providerLimited = false,
    ): PublicationPage {
        $nextCursor = data_get($response->json(), 'paging.next')
            ? data_get($response->json(), 'paging.cursors.after')
            : null;

        return new PublicationPage(
            publications: $publications,
            nextCursor: $crossedCutoff ? null : (is_string($nextCursor) ? $nextCursor : null),
            providerExhausted: $crossedCutoff || ! is_string($nextCursor),
            providerLimited: $providerLimited,
        );
    }

    protected function publishedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    /** @return array<string, string>|null */
    protected function preview(?string $url): ?array
    {
        return filled($url) ? ['thumbnail_url' => $url] : null;
    }

    private function retryAt(Response $response): ?CarbonImmutable
    {
        $retryAfter = $response->header('Retry-After');

        if (! is_string($retryAfter) || $retryAfter === '') {
            return null;
        }

        return ctype_digit($retryAfter)
            ? CarbonImmutable::now('UTC')->addSeconds((int) $retryAfter)
            : CarbonImmutable::parse($retryAfter)->utc();
    }
}
