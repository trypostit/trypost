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
 * Every Facebook Page a login can publish to.
 *
 * `/me/accounts` only returns Pages the person holds a Page role on, so a Business
 * Portfolio admin — the New Pages Experience norm — gets nothing there. The
 * portfolio's `owned_pages` and `client_pages` edges are read too and merged by id.
 *
 * `/me/accounts` is the list everything else is added to, so it is all or nothing:
 * failing it — running out of budget included — aborts rather than handing back a base
 * this walk cannot vouch for. Everything after it keeps what arrived. A refusal there
 * is an answer — a Page this login cannot enumerate is one it cannot get a token for,
 * so it was never connectable. An unknown is not: a throttle, a hiccup, a budget or a
 * ceiling leaves the walk unable to vouch for itself, and it says so, so no caller
 * auto-connects off a list that may be missing something.
 */
class ManagedPages
{
    private const PER_PAGE = 100;

    private const EDGES_PER_ROUND = 20;

    private const PORTFOLIO_SCOPE = 'business_management';

    /** Portfolios read, and the page size asked of `/me/businesses`, which is read once. */
    public const MAX_PORTFOLIOS = 100;

    /** Cursor requests allowed across the whole walk; these cannot be pooled. */
    public const MAX_CONTINUATIONS = 25;

    private bool $complete = true;

    private int $continuations = 0;

    private readonly float $deadline;

    private function __construct(
        private readonly string $graphApi,
        private readonly string $userToken,
        private readonly string $fields,
        ?float $deadline,
    ) {
        $this->deadline = $deadline ?? microtime(true) + (int) config('trypost.meta_page_walk_seconds');
    }

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
        ?float $deadline = null,
    ): ManagedPageList {
        return (new self($graphApi, $userToken, $fields, $deadline))->walk($grantedScopes);
    }

    /**
     * Meta returns `access_token` on a Page only when the login holds a role on that
     * Page — being in the portfolio that owns it is not enough — so a portfolio can
     * list Pages this login cannot post to. Connecting one produces an account that
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
     * @param  array<int, string>  $grantedScopes
     */
    private function walk(array $grantedScopes): ManagedPageList
    {
        $pages = collect(GraphPaginator::all("{$this->graphApi}/me/accounts", $this->query(), $this->deadline));

        if (in_array(self::PORTFOLIO_SCOPE, $grantedScopes, true)) {
            $pages = $pages->concat($this->portfolioPages());
        }

        return new ManagedPageList(
            $pages
                ->sortBy(fn (array $page) => filled(data_get($page, 'access_token')) ? 0 : 1)
                ->unique(fn (array $page) => (string) data_get($page, 'id'))
                ->values()
                ->all(),
            $this->complete,
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function portfolioPages(): Collection
    {
        return collect($this->businessIds())
            ->crossJoin(['owned_pages', 'client_pages'])
            ->map(fn (array $edge) => Uri::of("{$this->graphApi}/{$edge[0]}/{$edge[1]}")->withQuery($this->query())->value())
            ->chunk(self::EDGES_PER_ROUND)
            ->flatMap($this->readRound(...));
    }

    /** No single request may outlive the budget by its own timeout. */
    private function timeout(): int
    {
        return max(1, min(15, (int) ceil($this->deadline - microtime(true))));
    }

    /** Every per-request budget is bounded, but the walk sits in an OAuth callback. */
    private function outOfTime(): bool
    {
        if (microtime(true) < $this->deadline) {
            return false;
        }

        $this->complete = false;

        return true;
    }

    /**
     * @param  Collection<int, string>  $urls
     * @return Collection<int, array<string, mixed>>
     */
    private function readRound(Collection $urls): Collection
    {
        if ($this->outOfTime()) {
            return collect();
        }

        $urls = $urls->values();

        $responses = Http::pool(fn (Pool $pool) => $urls
            ->map(fn (string $url) => $pool->timeout($this->timeout())->connectTimeout(5)->get($url))
            ->all());

        return $urls->flatMap(function (string $url, int $index) use ($responses) {
            $response = data_get($responses, $index);

            if (! $response instanceof Response) {
                $this->complete = false;

                return [];
            }

            if ($response->failed()) {
                $this->note($url, $response);

                return [];
            }

            return $response->collect('data')->concat($this->rest($url, $response->json('paging.next')));
        });
    }

    /**
     * Follows what is left of an edge, one budgeted request at a time. A cursor cannot
     * be pooled, so this is the only serial path in the walk. Whatever arrived before a
     * cut-off is kept; only the walk's completeness is lost.
     *
     * @return list<array<string, mixed>>
     */
    private function rest(string $url, mixed $next): array
    {
        $pages = [];

        while (is_string($next) && filled($next)) {
            if ($this->continuations >= self::MAX_CONTINUATIONS || $this->outOfTime() || Uri::of($next)->host() !== Uri::of($url)->host()) {
                $this->complete = false;

                break;
            }

            $this->continuations++;

            try {
                $response = Http::timeout($this->timeout())->connectTimeout(5)->get($next);
            } catch (ConnectionException) {
                $this->complete = false;

                break;
            }

            if ($response->failed()) {
                GraphPaginator::failure($next, $response);
                $this->complete = false;

                break;
            }

            $pages = [...$pages, ...$response->collect('data')->all()];
            $next = $response->json('paging.next');
        }

        return $pages;
    }

    /**
     * Reading one page is what bounds the walk: paginating here would let one login
     * spawn thousands of edge reads. More portfolios than fit is incomplete, not failed.
     *
     * A refusal here is not an answer about any Page. Refusing one edge says those Pages
     * are unreadable, and unreadable is unconnectable; refusing the index says no edge
     * was ever read, and Meta's own reference has Pages carrying a token on those edges
     * while `/me/accounts` omits them, which is the whole reason this walk exists.
     *
     * @return list<string>
     */
    private function businessIds(): array
    {
        $url = "{$this->graphApi}/me/businesses";

        try {
            $response = Http::timeout($this->timeout())->connectTimeout(5)->get($url, [
                'access_token' => $this->userToken,
                'limit' => self::MAX_PORTFOLIOS,
            ]);
        } catch (ConnectionException) {
            $this->complete = false;

            return [];
        }

        if ($response->failed()) {
            GraphPaginator::failure($url, $response);
            $this->complete = false;

            return [];
        }

        if (filled($response->json('paging.next'))) {
            $this->complete = false;
        }

        return $response->collect('data')
            ->pluck('id')
            ->filter()
            ->map(strval(...))
            ->take(self::MAX_PORTFOLIOS)
            ->values()
            ->all();
    }

    /**
     * A rejection is Meta answering that this login reaches nothing there. Anything
     * else leaves the edge unread, which the walk cannot vouch for.
     */
    private function note(string $url, Response $response): void
    {
        if (GraphPaginator::failure($url, $response)->transient) {
            $this->complete = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        return ['access_token' => $this->userToken, 'fields' => $this->fields, 'limit' => self::PER_PAGE];
    }
}
