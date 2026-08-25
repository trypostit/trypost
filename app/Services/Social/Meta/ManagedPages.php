<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
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
 * Those edges need `business_management`. A login Meta reports as having refused it
 * skips them outright — walking anyway would spend a request on a certain 403 and
 * log it at error level on every otherwise-successful connect. A refusal Meta does
 * not report is not assumed: the walk runs, and a rejection there still reads as
 * "this login reaches no portfolio pages" rather than failing the connect.
 *
 * A throttle, an upstream hiccup or more portfolios than the ceiling walks leaves the
 * real list unknown. None of that denies the connect — the Pages that did arrive are
 * still returned — but the walk reports itself incomplete so no caller auto-connects
 * off a list it cannot vouch for. Only `/me/accounts` itself failing is fatal: with
 * nothing to stand on there is no list at all.
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
     * Portfolios walked, and the page size asked of `/me/businesses` — the walk
     * reads that one page and no more, so this is what bounds the whole thing.
     */
    public const MAX_PORTFOLIOS = 100;

    /**
     * The permission Meta requires to read a portfolio's Page edges.
     */
    private const PORTFOLIO_SCOPE = 'business_management';

    /**
     * @param  array<int, string>  $grantedScopes
     *
     * @throws IncompleteMetaGraphPaginationException when `/me/accounts` itself fails
     */
    public static function forUser(
        string $graphApi,
        string $userToken,
        string $fields,
        array $grantedScopes = [self::PORTFOLIO_SCOPE],
    ): ManagedPageList {
        $query = ['access_token' => $userToken, 'fields' => $fields, 'limit' => self::PER_PAGE];
        $pages = collect(GraphPaginator::all("{$graphApi}/me/accounts", $query));

        if (! in_array(self::PORTFOLIO_SCOPE, $grantedScopes, true)) {
            return new ManagedPageList(self::merge($pages), true);
        }

        $complete = true;
        $pages = $pages->concat(self::portfolioPages($graphApi, $userToken, $query, $complete));

        return new ManagedPageList(self::merge($pages), $complete);
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
     * One record per Page id, preferring whichever copy carries a token.
     *
     * @param  Collection<int, array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private static function merge(Collection $pages): array
    {
        return $pages
            ->sortBy(fn (array $page) => filled(data_get($page, 'access_token')) ? 0 : 1)
            ->unique(fn (array $page) => (string) data_get($page, 'id'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private static function portfolioPages(string $graphApi, string $userToken, array $query, bool &$complete): Collection
    {
        $rounds = collect(self::businessIds($graphApi, $userToken, $complete))
            ->crossJoin(['owned_pages', 'client_pages'])
            ->map(fn (array $edge) => Uri::of("{$graphApi}/{$edge[0]}/{$edge[1]}")->withQuery($query)->value())
            ->chunk(self::EDGES_PER_ROUND);

        $pages = collect();

        foreach ($rounds as $round) {
            $pages = $pages->concat(self::readRound($round, $complete));
        }

        return $pages;
    }

    /**
     * Reads a round of edges at once, classifying each answer where it lands so a
     * failure costs one request rather than two. A `paging.next` is followed only
     * when it stays on the host the edge was read from; anything else is handed to
     * GraphPaginator, whose own guard refuses it.
     *
     * @param  Collection<int, string>  $urls
     * @return Collection<int, array<string, mixed>>
     */
    private static function readRound(Collection $urls, bool &$complete): Collection
    {
        $urls = $urls->values();

        $responses = Http::pool(fn (Pool $pool) => $urls
            ->map(fn (string $url) => $pool->timeout(15)->connectTimeout(5)->get($url))
            ->all());

        $pages = collect();

        foreach ($urls as $index => $url) {
            $response = data_get($responses, $index);

            if (! $response instanceof Response) {
                $complete = false;

                continue;
            }

            if ($response->failed()) {
                $complete = GraphPaginator::failure($url, $response)->transient ? false : $complete;

                continue;
            }

            $pages = $pages->concat($response->collect('data'));
            $next = $response->json('paging.next');

            if (! is_string($next) || blank($next)) {
                continue;
            }

            $pages = $pages->concat(self::rest(
                Uri::of($next)->host() === Uri::of($url)->host() ? $next : $url,
                $complete,
            ));
        }

        return $pages;
    }

    /**
     * Follows what is left of an edge. Anything short of the whole remainder — a
     * rejection included, since a page already arrived — leaves the walk unable to
     * vouch for the edge.
     *
     * @return list<array<string, mixed>>
     */
    private static function rest(string $url, bool &$complete): array
    {
        try {
            return GraphPaginator::all($url);
        } catch (IncompleteMetaGraphPaginationException) {
            $complete = false;

            return [];
        }
    }

    /**
     * The portfolios to walk, from a single request. Reading only the first page
     * is what actually bounds the work: paginating here would let one login spawn
     * thousands of edge reads inside a synchronous OAuth callback. More portfolios
     * than fit means the walk cannot see all of them, which is an incomplete walk,
     * not a failed one.
     *
     * @return list<string>
     */
    private static function businessIds(string $graphApi, string $userToken, bool &$complete): array
    {
        $url = "{$graphApi}/me/businesses";

        try {
            $response = Http::timeout(15)->connectTimeout(5)->get($url, [
                'access_token' => $userToken,
                'limit' => self::MAX_PORTFOLIOS,
            ]);
        } catch (ConnectionException) {
            $complete = false;

            return [];
        }

        if ($response->failed()) {
            $complete = GraphPaginator::failure($url, $response)->transient ? false : $complete;

            return [];
        }

        if (filled($response->json('paging.next'))) {
            $complete = false;
        }

        return $response->collect('data')
            ->pluck('id')
            ->filter()
            ->map(strval(...))
            ->take(self::MAX_PORTFOLIOS)
            ->values()
            ->all();
    }
}
