<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use Throwable;

/**
 * Collects every item from a paginated Meta Graph API edge by following `paging.next`.
 */
class GraphPaginator
{
    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    public static function all(string $url, array $query = []): array
    {
        $items = [];
        $ok = 0;
        $seen = [];
        $next = (string) Uri::of($url)->withQuery($query);

        while (filled($next)) {
            if (isset($seen[$next])) {
                Log::warning('Meta Graph pagination stopped: repeated paging URL', [
                    'url' => TokenRedactor::redact($next),
                ]);

                break;
            }

            $seen[$next] = true;

            try {
                $response = Http::timeout(15)->connectTimeout(5)->get($next);
            } catch (ConnectionException $e) {
                Log::error('Meta Graph pagination connection failed', [
                    'url' => TokenRedactor::redact($next),
                    'error' => $e->getMessage(),
                ]);

                return self::emptyOrIncomplete($ok, $e);
            }

            if ($response->failed()) {
                Log::error('Meta Graph pagination request failed', [
                    'url' => TokenRedactor::redact($next),
                    'status' => $response->status(),
                    'body' => TokenRedactor::redact($response->body()),
                ]);

                return self::emptyOrIncomplete($ok);
            }

            $ok++;
            $items = [...$items, ...$response->collect('data')->values()->all()];

            $candidate = $response->json('paging.next');
            $next = is_string($candidate) && filled($candidate) ? $candidate : null;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    private static function emptyOrIncomplete(int $successfulRequests, ?Throwable $previous = null): array
    {
        if ($successfulRequests > 0) {
            throw new IncompleteMetaGraphPaginationException($previous);
        }

        return [];
    }
}
