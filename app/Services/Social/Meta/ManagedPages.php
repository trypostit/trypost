<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Every Facebook Page a login can publish to, gathered from all the edges Meta lists them under.
 *
 * `/me/accounts` only returns Pages the person holds a Page role on. Someone whose
 * access comes from a Business Portfolio assignment — an admin of a Page owned by
 * someone else's portfolio, the norm under the New Pages Experience — gets an empty
 * list there, so the portfolio's own `owned_pages` and `client_pages` edges are read
 * too and merged by Page id.
 *
 * Those edges need `business_management`, which a login may not grant, so they are
 * read only once the permission is confirmed and never turn a usable `/me/accounts`
 * list into a failure.
 */
class ManagedPages
{
    private const PER_PAGE = 100;

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
            ->filter(fn (array $page) => filled(data_get($page, 'access_token')))
            ->unique(fn (array $page) => (string) data_get($page, 'id'))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function businessIds(string $graphApi, string $userToken): array
    {
        if (! self::grantsBusinessManagement($graphApi, $userToken)) {
            return [];
        }

        return collect(self::optional("{$graphApi}/me/businesses", [
            'access_token' => $userToken,
            'limit' => self::PER_PAGE,
        ]))
            ->pluck('id')
            ->filter()
            ->map(strval(...))
            ->values()
            ->all();
    }

    private static function grantsBusinessManagement(string $graphApi, string $userToken): bool
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->get("{$graphApi}/me/permissions", [
                'access_token' => $userToken,
            ]);
        } catch (ConnectionException) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        return $response->collect('data')->contains(
            fn ($permission) => data_get($permission, 'permission') === 'business_management'
                && data_get($permission, 'status') === 'granted',
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private static function optional(string $url, array $query): array
    {
        try {
            return GraphPaginator::all($url, $query);
        } catch (IncompleteMetaGraphPaginationException) {
            return [];
        }
    }
}
