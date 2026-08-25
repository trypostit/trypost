<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;

/**
 * Every Facebook Page a login can publish to, gathered from all the edges Meta lists them under.
 *
 * `/me/accounts` only returns Pages the person holds a Page role on. Someone whose
 * access comes from a Business Portfolio assignment — an admin of a Page owned by
 * someone else's portfolio, the norm under the New Pages Experience — gets an empty
 * list there, so the portfolio's own `owned_pages` and `client_pages` edges are read
 * too and merged by Page id.
 *
 * Those edges need `business_management`, which a login may not grant, so a rejection
 * there reads as "this login reaches no portfolio pages" rather than failing the
 * connect. A throttle or an upstream hiccup is not a rejection — it leaves the real
 * list unknown, and is raised so no caller auto-connects a half-fetched list. Running
 * out of ceiling is the same kind of unknown and is raised too.
 */
class ManagedPages
{
    private const PER_PAGE = 100;

    /**
     * Portfolio edges read concurrently per round. The walk sits inside a
     * synchronous OAuth callback, where serial round trips are what break it.
     */
    private const EDGES_PER_ROUND = 20;

    /**
     * Hard ceiling on portfolios walked, matching GraphPaginator::MAX_PAGES in
     * spirit: far above any real membership, there only to bound a runaway.
     * Passing it means the real list is unknown, so it raises rather than
     * quietly handing back whatever fit.
     */
    public const MAX_PORTFOLIOS = 100;

    /**
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    public static function forUser(string $graphApi, string $userToken, string $fields): array
    {
        $query = ['access_token' => $userToken, 'fields' => $fields, 'limit' => self::PER_PAGE];

        return collect(GraphPaginator::all("{$graphApi}/me/accounts", $query))
            ->concat(self::portfolioPages($graphApi, $userToken, $query))
            ->sortBy(fn (array $page) => filled(data_get($page, 'access_token')) ? 0 : 1)
            ->unique(fn (array $page) => (string) data_get($page, 'id'))
            ->values()
            ->all();
    }

    /**
     * The Pages this login can actually post to. A Page Meta lists without an
     * `access_token` — the shape of a login that declined `pages_read_engagement`
     * on Meta's per-permission toggles — would connect into an account that
     * cannot publish, so callers separate it from a Page they never had.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    public static function publishable(array $pages): array
    {
        return collect($pages)
            ->filter(fn (array $page) => filled(data_get($page, 'access_token')))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private static function portfolioPages(string $graphApi, string $userToken, array $query): Collection
    {
        return collect(self::businessIds($graphApi, $userToken))
            ->crossJoin(['owned_pages', 'client_pages'])
            ->map(fn (array $edge) => Uri::of("{$graphApi}/{$edge[0]}/{$edge[1]}")->withQuery($query)->value())
            ->chunk(self::EDGES_PER_ROUND)
            ->flatMap(self::readRound(...));
    }

    /**
     * Reads a round of edges at once. A URL that does not come back cleanly is
     * handed to GraphPaginator, which owns the one place that logs a Graph
     * failure and decides whether it is a rejection or an unknown.
     *
     * @param  Collection<int, string>  $urls
     * @return Collection<int, array<string, mixed>>
     */
    private static function readRound(Collection $urls): Collection
    {
        $urls = $urls->values();

        $responses = Http::pool(fn (Pool $pool) => $urls
            ->map(fn (string $url) => $pool->timeout(15)->connectTimeout(5)->get($url))
            ->all());

        return $urls->flatMap(function (string $url, int $index) use ($responses) {
            $response = data_get($responses, $index);

            if (! $response instanceof Response || $response->failed()) {
                return self::optional($url);
            }

            $next = $response->json('paging.next');

            return $response->collect('data')->concat(
                is_string($next) && filled($next) ? self::optional($next) : [],
            );
        });
    }

    /**
     * @return list<string>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    private static function businessIds(string $graphApi, string $userToken): array
    {
        $ids = collect(self::optional("{$graphApi}/me/businesses", [
            'access_token' => $userToken,
            'limit' => self::PER_PAGE,
        ]))
            ->pluck('id')
            ->filter()
            ->map(strval(...))
            ->values();

        if ($ids->count() > self::MAX_PORTFOLIOS) {
            Log::error('Meta portfolio walk stopped: ceiling reached', [
                'found' => $ids->count(),
                'ceiling' => self::MAX_PORTFOLIOS,
            ]);

            throw new IncompleteMetaGraphPaginationException;
        }

        return $ids->all();
    }

    /**
     * An edge this login is simply not allowed to read answers with an empty
     * list. A throttle, an upstream hiccup or a truncated walk leaves the real
     * list unknown, and is raised so the caller never auto-connects whatever
     * happened to arrive first.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    private static function optional(string $url, array $query = []): array
    {
        try {
            return GraphPaginator::all($url, $query);
        } catch (IncompleteMetaGraphPaginationException $e) {
            if ($e->transient) {
                throw $e;
            }

            return [];
        }
    }
}
