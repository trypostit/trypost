<?php

declare(strict_types=1);

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use App\Services\Social\Meta\ManagedPages;
use Illuminate\Support\Facades\Http;

const MANAGED_PAGES_FIELDS = 'id,name,access_token';

function managedPagesGraphApi(): string
{
    return (string) config('trypost.platforms.facebook.graph_api');
}

function managedPagesPermissions(bool $businessManagement): array
{
    return ['data' => array_filter([
        ['permission' => 'pages_show_list', 'status' => 'granted'],
        $businessManagement ? ['permission' => 'business_management', 'status' => 'granted'] : null,
    ])];
}

test('business portfolio pages are found when me/accounts is empty', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/permissions*" => Http::response(managedPagesPermissions(true), 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Owned Page', 'access_token' => 'owned-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response([
            'data' => [['id' => 'page_2', 'name' => 'Client Page', 'access_token' => 'client-token']],
        ], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(2)
        ->and(data_get($pages, '0.id'))->toBe('page_1')
        ->and(data_get($pages, '1.id'))->toBe('page_2');
});

test('a page listed in both me/accounts and a portfolio is returned once, keeping its user token', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/permissions*" => Http::response(managedPagesPermissions(true), 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'portfolio-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.access_token'))->toBe('role-token');
});

test('portfolio pages the login cannot get a token for are dropped', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/permissions*" => Http::response(managedPagesPermissions(true), 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [
                ['id' => 'page_1', 'name' => 'No Access'],
                ['id' => 'page_2', 'name' => 'Usable', 'access_token' => 'page-token'],
            ],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_2');
});

test('portfolio edges are left alone when business_management was not granted', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/permissions*" => Http::response(managedPagesPermissions(false), 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/businesses'));
});

test('a failing portfolio edge keeps the pages me/accounts already returned', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/permissions*" => Http::response(managedPagesPermissions(true), 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response(['error' => ['message' => 'nope']], 400),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['error' => ['message' => 'nope']], 400),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_1');
});

test('a failing me/accounts still aborts instead of reporting no pages', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['error' => ['message' => 'fail']], 400),
    ]);

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
})->throws(IncompleteMetaGraphPaginationException::class);
