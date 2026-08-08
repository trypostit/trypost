<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

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
     */
    public static function all(string $url, array $query = [], int $maxPages = 50): array
    {
        $items = [];
        $nextUrl = $url;
        $params = $query;
        $pagesFetched = 0;

        while ($nextUrl !== null && $pagesFetched < $maxPages) {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->get($nextUrl, $params);

            if ($response->failed()) {
                Log::error('Meta Graph pagination request failed', [
                    'url' => $nextUrl,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                break;
            }

            $payload = $response->json();
            $chunk = data_get($payload, 'data', []);

            if (is_array($chunk) && $chunk !== []) {
                array_push($items, ...array_values($chunk));
            }

            $next = data_get($payload, 'paging.next');
            $nextUrl = is_string($next) && $next !== '' ? $next : null;
            // Absolute next URLs already include the query string.
            $params = [];
            $pagesFetched++;
        }

        return $items;
    }
}
