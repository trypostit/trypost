<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteGraphPaginationException;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Collects every item from a paginated Meta Graph API edge by following `paging.next`.
 *
 * `/me/accounts` (and similar edges) return at most one page of results per request.
 * With granular Page permissions, the first response can even be empty while authorized
 * Pages appear only on later pages — callers must paginate or they will miss Pages.
 */
class GraphPaginator
{
    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteGraphPaginationException When a later page fails after earlier pages succeeded.
     */
    public static function all(string $url, array $query = []): array
    {
        $items = [];
        $nextUrl = $url;
        $params = $query;
        $successfulRequests = 0;
        /** @var array<string, true> */
        $seenUrls = [];

        while ($nextUrl !== null) {
            $requestKey = self::requestKey($nextUrl, $params);

            if (isset($seenUrls[$requestKey])) {
                Log::warning('Meta Graph pagination stopped: repeated paging URL', [
                    'url' => TokenRedactor::redact($nextUrl),
                ]);

                break;
            }

            $seenUrls[$requestKey] = true;

            try {
                $response = Http::timeout(15)
                    ->connectTimeout(5)
                    ->get($nextUrl, $params);
            } catch (ConnectionException $e) {
                Log::error('Meta Graph pagination connection failed', [
                    'url' => TokenRedactor::redact($nextUrl),
                    'error' => $e->getMessage(),
                ]);

                if ($successfulRequests > 0) {
                    throw new IncompleteGraphPaginationException(previous: $e);
                }

                return [];
            }

            if ($response->failed()) {
                Log::error('Meta Graph pagination request failed', [
                    'url' => TokenRedactor::redact($nextUrl),
                    'status' => $response->status(),
                    'body' => TokenRedactor::redact($response->body()),
                ]);

                if ($successfulRequests > 0) {
                    throw new IncompleteGraphPaginationException;
                }

                return [];
            }

            $successfulRequests++;

            $payload = $response->json();
            $chunk = data_get($payload, 'data', []);

            if (is_array($chunk) && $chunk !== []) {
                array_push($items, ...array_values($chunk));
            }

            $next = data_get($payload, 'paging.next');
            $nextUrl = is_string($next) && $next !== '' ? $next : null;
            // Absolute next URLs already include the query string.
            $params = [];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function requestKey(string $url, array $params): string
    {
        if ($params === []) {
            return $url;
        }

        ksort($params);

        return $url.'?'.http_build_query($params);
    }
}
