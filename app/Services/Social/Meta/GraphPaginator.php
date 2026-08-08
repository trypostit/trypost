<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
        $items = collect();
        $fetched = 0;
        $seen = [];
        $next = Uri::of($url)->withQuery($query)->value();

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
                return self::abort($next, $fetched, $e);
            }

            if ($response->failed()) {
                return self::abort($next, $fetched, response: $response);
            }

            $fetched++;
            $items = $items->concat($response->collect('data'));
            $candidate = $response->json('paging.next');
            $next = when(is_string($candidate) && filled($candidate), $candidate);
        }

        return $items->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    private static function abort(string $url, int $fetched, ?Throwable $e = null, ?Response $response = null): array
    {
        Log::error($e ? 'Meta Graph pagination connection failed' : 'Meta Graph pagination request failed', array_filter([
            'url' => TokenRedactor::redact($url),
            'error' => $e?->getMessage(),
            'status' => $response?->status(),
            'body' => $response ? TokenRedactor::redact($response->body()) : null,
        ]));

        throw_if($fetched > 0, IncompleteMetaGraphPaginationException::class, $e);

        return [];
    }
}
