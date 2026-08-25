<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use Illuminate\Support\Facades\Log;

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
 * list unknown, and is raised so no caller auto-connects a half-fetched list.
 */
class ManagedPages
{
    private const PER_PAGE = 100;

    /**
     * Hard ceiling on portfolios walked. Each one costs two more paginated
     * edges inside a synchronous OAuth callback, so this plays the same role
     * for the portfolio loop that GraphPaginator::MAX_PAGES plays for a single
     * edge: far above any real membership, there only to bound a runaway.
     */
    public const MAX_PORTFOLIOS = 25;

    /**
     * @return list<array<string, mixed>>
     *
     * @throws IncompleteMetaGraphPaginationException
     */
    public static function forUser(string $graphApi, string $userToken, string $fields): array
    {
        $pages = collect(GraphPaginator::all("{$graphApi}/me/accounts", [
            'access_token' => $userToken,
            'fields' => $fields,
            'limit' => self::PER_PAGE,
        ]));

        foreach (self::businessIds($graphApi, $userToken) as $businessId) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                $pages = $pages->concat(self::optional("{$graphApi}/{$businessId}/{$edge}", [
                    'access_token' => $userToken,
                    'fields' => $fields,
                    'limit' => self::PER_PAGE,
                ]));
            }
        }

        return $pages
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
     * @return list<string>
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
            Log::warning('Meta portfolio walk truncated', [
                'found' => $ids->count(),
                'walked' => self::MAX_PORTFOLIOS,
            ]);
        }

        return $ids->take(self::MAX_PORTFOLIOS)->all();
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
    private static function optional(string $url, array $query): array
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
