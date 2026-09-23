<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Models\SocialAccount;
use App\Support\Analytics\MetaAnalyticsResponse;
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

        return MetaAnalyticsResponse::successful($response, 'publication history collection');
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
}
