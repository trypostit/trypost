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
 * Collects every item from a paginated Meta Graph edge by following `paging.next`.
 *
 * Failures and pathological cases (repeated next URL, off-host next URL, extreme page
 * count) throw, so no caller confuses an error with an empty list.
 */
class GraphPaginator
{
    /**
     * Hard ceiling on Graph API pages per edge. Far above any real Page list;
     * exists only to bound a runaway OAuth callback.
     */
    public const MAX_PAGES = 100;

    /**
     * @param  array<string, mixed>  $query
     * @param  float|null  $deadline  microtime after which no *further* page is fetched; the first always is
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    public static function all(string $url, array $query = [], ?float $deadline = null): array
    {
        $items = collect();
        $fetched = 0;
        $seen = [];
        $allowedHost = Uri::of($url)->host();
        $next = Uri::of($url)->withQuery($query)->value();

        while (filled($next)) {
            if ($fetched >= self::MAX_PAGES) {
                self::abort($next, $fetched, reason: 'Meta Graph pagination stopped: page limit reached');
            }

            if (isset($seen[$next])) {
                self::abort($next, $fetched, reason: 'Meta Graph pagination stopped: repeated paging URL');
            }

            if ($fetched > 0 && $deadline !== null && microtime(true) >= $deadline) {
                self::abort($next, $fetched, reason: 'Meta Graph pagination stopped: out of time');
            }

            $seen[$next] = true;

            try {
                $response = Http::timeout(15)->connectTimeout(5)->get($next);
            } catch (ConnectionException $e) {
                self::abort($next, $fetched, $e);
            }

            if ($response->failed()) {
                self::abort($next, $fetched, response: $response);
            }

            $fetched++;
            $items = $items->concat($response->collect('data'));
            $candidate = $response->json('paging.next');

            if (! is_string($candidate) || blank($candidate)) {
                $next = null;

                continue;
            }

            if (Uri::of($candidate)->host() !== $allowedHost) {
                self::abort($candidate, $fetched, reason: 'Meta Graph pagination stopped: unexpected paging host');
            }

            $next = $candidate;
        }

        return $items->values()->all();
    }

    /** Classify and log a failed response a caller read itself, rather than walked here. */
    public static function failure(string $url, Response $response): IncompleteMetaGraphPaginationException
    {
        return self::describe($url, 0, response: $response);
    }

    /**
     * @throws IncompleteMetaGraphPaginationException
     */
    private static function abort(
        string $url,
        int $fetched,
        ?Throwable $e = null,
        ?Response $response = null,
        ?string $reason = null,
    ): never {
        throw self::describe($url, $fetched, $e, $response, $reason);
    }

    /** A confirmed rejection is Meta answering, so it warns; an unknown stays an error. */
    private static function describe(
        string $url,
        int $fetched,
        ?Throwable $e = null,
        ?Response $response = null,
        ?string $reason = null,
    ): IncompleteMetaGraphPaginationException {
        $transient = $response === null || GraphError::isTransientFailure($response);

        $message = $reason ?? ($e ? 'Meta Graph pagination connection failed' : 'Meta Graph pagination request failed');
        $context = array_filter([
            'url' => TokenRedactor::redact($url),
            'error' => $e?->getMessage(),
            'status' => $response?->status(),
            'body' => $response ? TokenRedactor::redact($response->body()) : null,
            'fetched' => $fetched > 0 ? $fetched : null,
        ]);

        $transient ? Log::error($message, $context) : Log::warning($message, $context);

        return new IncompleteMetaGraphPaginationException($e, transient: $transient);
    }
}
